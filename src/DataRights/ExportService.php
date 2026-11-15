<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use FancyCompliance\Events\ExportDownloaded;
use FancyCompliance\Events\ExportFailed;
use FancyCompliance\Events\ExportReady;
use FancyCompliance\Events\ExportRequested;
use FancyCompliance\Jobs\BuildExport;
use FancyCompliance\Models\DataExport;
use FancyCompliance\Support\Reporter;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Export requests: limits, building, storage and the single-use download.
 *
 * - The subject is stored as a keyed hash; the subject itself is kept
 *   encrypted only until the export is built.
 * - The file is encrypted with the app key on the host's disk and deleted at
 *   its time to live (or after the first download, when the host says so).
 * - A download needs the authenticated subject AND a single-use token; every
 *   read of a ready export issues a fresh token, and a download spends it.
 */
final class ExportService
{
    public const LIST_LIMIT = 10;

    public function __construct(
        private readonly Container $app,
        private readonly DataRightsManager $rights,
        private readonly Events $events,
    ) {}

    /** @throws DataRightsRefused */
    public function request(DataSubject $target, string $format): DataExport
    {
        $config = $this->rights->config();
        if (! in_array($format, $config->exportFormats(), true)) {
            throw new DataRightsRefused('invalid_input', 422);
        }
        $hash = $this->hash($target);
        $now = Carbon::now();

        $pending = DataExport::query()->where('subject_hash', $hash)->where('status', DataExport::PENDING)->first();
        if ($pending !== null) {
            throw new DataRightsRefused('export_in_progress', 409, ['export' => $this->view($pending, false)]);
        }
        $recent = DataExport::query()->where('subject_hash', $hash)->where('created_at', '>', $now->copy()->subDay())->orderBy('created_at')->get();
        if ($recent->count() >= $config->exportsPerDay()) {
            $retry = $recent->first()->created_at->copy()->addDay()->getTimestamp() - $now->getTimestamp();
            throw new DataRightsRefused('rate_limited', 429, ['retryAfter' => max(1, $retry)]);
        }

        $export = DataExport::query()->create([
            'id' => Pseudonyms::id(),
            'subject_hash' => $hash,
            'subject' => $this->encrypter()->encryptString(json_encode(['type' => $target->type, 'id' => $target->id], JSON_THROW_ON_ERROR)),
            'format' => $format,
            'status' => DataExport::PENDING,
            'created_at' => $now,
        ]);
        $this->events->dispatch(new ExportRequested($target, $export->id, $format));

        if ($config->queueEnabled()) {
            $job = new BuildExport($export->id);
            $job->connection = $config->queueConnection();
            $job->queue = $config->queueName();
            $this->app->make(Bus::class)->dispatch($job);
        } else {
            $this->build($export->id);
        }

        return $export->fresh() ?? $export;
    }

    /** Builds a pending export. Anything else is left alone, so a retried job is harmless. */
    public function build(string $id): void
    {
        $export = DataExport::query()->find($id);
        if ($export === null || $export->status !== DataExport::PENDING || $export->subject === null) {
            return;
        }
        $config = $this->rights->config();
        $subject = $this->subjectOf($export);
        $bundle = new ExportBundle($config->exportMaxBytes(), $config->exportMaxFiles());
        $failedRegistrant = null;

        try {
            $keys = $this->rights->keysFor($subject);
            foreach ($this->rights->registrants() as $registrant) {
                $failedRegistrant = $registrant->key();
                try {
                    $registrant->export($keys, $bundle->writerFor($registrant->key()));
                } catch (ExportLimitExceeded $e) {
                    throw $e;
                } catch (Throwable $e) {
                    Reporter::report($this->app, $e);
                    $this->fail($export, $subject, 'registrant_failed', $registrant->key());

                    return;
                }
            }
            $failedRegistrant = null;
            $meta = [['type' => $subject->type, 'id' => $subject->id], Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z')];
            $bytes = $export->format === 'zip' ? $bundle->toZip(...$meta) : $bundle->toJson(...$meta);
            $path = $config->exportDirectory().'/'.$export->id.'.'.$export->format.'.enc';
            $this->disk()->put($path, $this->encrypter()->encryptString($bytes));
        } catch (ExportLimitExceeded $e) {
            $this->fail($export, $subject, $e->errorCode, null);

            return;
        } catch (Throwable $e) {
            Reporter::report($this->app, $e);
            $this->fail($export, $subject, 'build_failed', $failedRegistrant);

            return;
        }

        $now = Carbon::now();
        $export->forceFill([
            'status' => DataExport::READY,
            'subject' => null,
            'path' => $path,
            'bytes' => strlen($bytes),
            'ready_at' => $now,
            'expires_at' => $now->copy()->addHours($config->exportTtlHours()),
        ])->save();
        $this->events->dispatch(new ExportReady($subject, $export->id, $export->format, strlen($bytes)));
    }

    /** @return list<DataExport> newest first */
    public function list(DataSubject $target): array
    {
        return DataExport::query()->where('subject_hash', $this->hash($target))
            ->orderByDesc('created_at')->limit(self::LIST_LIMIT)->get()->all();
    }

    /** @return array{perDay: int, remainingToday: int} */
    public function limits(DataSubject $target): array
    {
        $perDay = $this->rights->config()->exportsPerDay();
        $used = DataExport::query()->where('subject_hash', $this->hash($target))
            ->where('created_at', '>', Carbon::now()->subDay())->count();

        return ['perDay' => $perDay, 'remainingToday' => max(0, $perDay - $used)];
    }

    public function find(DataSubject $target, mixed $id): ?DataExport
    {
        if (! Pseudonyms::isId($id)) {
            return null;
        }
        $export = DataExport::query()->find($id);

        return $export !== null && hash_equals($export->subject_hash, $this->hash($target)) ? $export : null;
    }

    /**
     * The protocol's ExportView. With `$issueToken`, a ready export gets a
     * fresh single-use download token, replacing any earlier one.
     *
     * @return array<string, mixed>
     */
    public function view(DataExport $export, bool $issueToken): array
    {
        $this->expireIfDue($export);
        $download = null;
        if ($issueToken && $export->status === DataExport::READY) {
            $token = Pseudonyms::token();
            $export->forceFill(['download_token_hash' => hash('sha256', $token)])->save();
            $prefix = trim((string) $this->app->make('config')->get('fancy-compliance.routes.prefix', 'fancy-compliance'), '/');
            $download = ['url' => '/'.($prefix === '' ? '' : $prefix.'/').'exports/'.$export->id.'/download?token='.$token];
        }

        return [
            'id' => $export->id,
            'format' => $export->format,
            'status' => $export->status,
            'requestedAt' => self::iso($export->created_at),
            'readyAt' => self::iso($export->ready_at),
            'expiresAt' => self::iso($export->expires_at),
            'bytes' => $export->bytes,
            'error' => $export->error,
            'download' => $download,
        ];
    }

    /**
     * The file, for an authenticated actor already allowed to export the
     * target. Spends the token.
     *
     * @throws DataRightsRefused
     */
    public function download(DataSubject $actor, DataSubject $target, mixed $id, mixed $token): Response
    {
        $export = $this->find($target, $id);
        if ($export === null) {
            throw new DataRightsRefused('not_found', 404);
        }
        $this->expireIfDue($export);
        if (in_array($export->status, [DataExport::EXPIRED, DataExport::DOWNLOADED], true)) {
            throw new DataRightsRefused('expired', 410);
        }
        if ($export->status !== DataExport::READY || $export->path === null || $export->download_token_hash === null
            || ! is_string($token) || strlen($token) !== 43
            || ! hash_equals($export->download_token_hash, hash('sha256', $token))) {
            throw new DataRightsRefused('not_found', 404);
        }

        $bytes = $this->encrypter()->decryptString((string) $this->disk()->get($export->path));
        $changes = ['download_token_hash' => null];
        if ($this->rights->config()->deleteAfterDownload()) {
            $this->disk()->delete($export->path);
            $changes += ['status' => DataExport::DOWNLOADED, 'path' => null];
        }
        $export->forceFill($changes)->save();
        $this->events->dispatch(new ExportDownloaded($actor, $export->id));

        $name = 'data-export-'.$export->created_at->utc()->format('Y-m-d').'.'.$export->format;

        return new Response($bytes, 200, [
            'Content-Type' => $export->format === 'zip' ? 'application/zip' : 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Deletes every export record of the subject, files included. Returns the rows deleted. */
    public function eraseFor(DataSubject $subject): int
    {
        $rows = DataExport::query()->where('subject_hash', $this->hash($subject))->get();
        foreach ($rows as $row) {
            if ($row->path !== null) {
                $this->disk()->delete($row->path);
            }
        }

        return DataExport::query()->whereIn('id', $rows->pluck('id')->all())->delete();
    }

    public function countFor(DataSubject $subject): int
    {
        return DataExport::query()->where('subject_hash', $this->hash($subject))->count();
    }

    /** Deletes files past their TTL. Returns how many (or would, on a dry run). */
    public function expireFiles(bool $dryRun): int
    {
        $due = DataExport::query()->whereNotNull('path')->where('expires_at', '<=', Carbon::now());
        if ($dryRun) {
            return $due->count();
        }
        $count = 0;
        foreach ($due->get() as $export) {
            $this->expire($export);
            $count++;
        }

        return $count;
    }

    /** Deletes records (and any file) created before the cutoff. */
    public function pruneRecords(Carbon $before, bool $dryRun): int
    {
        $old = DataExport::query()->where('created_at', '<', $before);
        if ($dryRun) {
            return $old->count();
        }
        $rows = $old->get();
        foreach ($rows as $row) {
            if ($row->path !== null) {
                $this->disk()->delete($row->path);
            }
        }

        return DataExport::query()->whereIn('id', $rows->pluck('id')->all())->delete();
    }

    public function hash(DataSubject $subject): string
    {
        return Pseudonyms::exportSubject($subject, $this->app->make('config')->get('app.key'));
    }

    private function expireIfDue(DataExport $export): void
    {
        if ($export->status === DataExport::READY && $export->expires_at !== null && $export->expires_at->lessThanOrEqualTo(Carbon::now())) {
            $this->expire($export);
        }
    }

    private function expire(DataExport $export): void
    {
        if ($export->path !== null) {
            $this->disk()->delete($export->path);
        }
        $export->forceFill([
            'status' => $export->status === DataExport::READY ? DataExport::EXPIRED : $export->status,
            'path' => null,
            'download_token_hash' => null,
        ])->save();
    }

    private function fail(DataExport $export, DataSubject $subject, string $error, ?string $registrant): void
    {
        $export->forceFill(['status' => DataExport::FAILED, 'error' => $error, 'failed_registrant' => $registrant, 'subject' => null])->save();
        $this->events->dispatch(new ExportFailed($subject, $export->id, $error, $registrant));
    }

    private function subjectOf(DataExport $export): DataSubject
    {
        $data = json_decode($this->encrypter()->decryptString((string) $export->subject), true, flags: JSON_THROW_ON_ERROR);

        return DataSubject::of((string) $data['type'], (string) $data['id']);
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return $this->app->make('filesystem')->disk($this->rights->config()->exportDisk());
    }

    private function encrypter(): \Illuminate\Contracts\Encryption\StringEncrypter
    {
        return $this->app->make('encrypter');
    }

    private static function iso(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
