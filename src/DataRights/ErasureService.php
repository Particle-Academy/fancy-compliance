<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use FancyCompliance\Events\ErasureCancelled;
use FancyCompliance\Events\ErasureExecuted;
use FancyCompliance\Events\ErasureRequested;
use FancyCompliance\Models\ErasureRecord;
use FancyCompliance\Models\ErasureRequest;
use FancyCompliance\Support\Reporter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Staged deletion: `pending` (the grace period) -> `cancelled`, or
 * `executing` -> `executed` | `partially_executed`.
 *
 * - The subject is held encrypted on the request only while it may still run,
 *   and is cleared once it is executed or cancelled. Everywhere else, and in
 *   the append-only audit trail, it is its HMAC pseudonym.
 * - Each registrant runs in its own transaction. A failure rolls that one back,
 *   is recorded, and leaves the request `partially_executed`; the others still
 *   run. Running the request again skips the registrants already done.
 * - An executed or cancelled request never runs again.
 */
final class ErasureService
{
    public function __construct(
        private readonly Container $app,
        private readonly DataRightsManager $rights,
        private readonly Events $events,
    ) {}

    /** @throws DataRightsRefused */
    public function request(DataSubject $target): ErasureRequest
    {
        $config = $this->rights->config();
        $pseudonym = $this->pseudonym($target);
        $grace = $config->graceDays();
        $now = Carbon::now();

        $pending = ErasureRequest::query()->where('pseudonym', $pseudonym)->where('status', ErasureRequest::PENDING)->first();
        if ($pending !== null) {
            throw new DataRightsRefused('erasure_pending', 409, ['erasure' => $this->view($pending)]);
        }
        $recent = ErasureRequest::query()->where('pseudonym', $pseudonym)->where('requested_at', '>', $now->copy()->subDay())->orderBy('requested_at')->get();
        if ($recent->count() >= $config->erasuresPerDay()) {
            $retry = $recent->first()->requested_at->copy()->addDay()->getTimestamp() - $now->getTimestamp();
            throw new DataRightsRefused('rate_limited', 429, ['retryAfter' => max(1, $retry)]);
        }
        [$blocked, $reason] = $this->rights->erasureBlock($target);
        if ($blocked) {
            throw new DataRightsRefused('erasure_blocked', 409, ['reason' => $reason]);
        }

        $request = ErasureRequest::query()->create([
            'id' => Pseudonyms::id(),
            'pseudonym' => $pseudonym,
            'subject' => $this->encrypter()->encryptString(json_encode(['type' => $target->type, 'id' => $target->id], JSON_THROW_ON_ERROR)),
            'status' => ErasureRequest::PENDING,
            'results' => [],
            'requested_at' => $now,
            'due_at' => $now->copy()->addDays($grace),
        ]);
        $this->audit($request, 'requested');
        $this->rights->erasureRequested($target, $request->id);
        $this->events->dispatch(new ErasureRequested($target, $request->id, $pseudonym, $request->due_at));

        if ($grace === 0) {
            return $this->execute($request->id) ?? $request;
        }

        return $request;
    }

    /** @throws DataRightsRefused */
    public function cancel(DataSubject $target, mixed $id): ErasureRequest
    {
        $request = $this->find($target, $id) ?? throw new DataRightsRefused('not_found', 404);
        if ($request->status !== ErasureRequest::PENDING) {
            throw new DataRightsRefused('not_cancellable', 409, ['erasure' => $this->view($request)]);
        }
        $request->forceFill(['status' => ErasureRequest::CANCELLED, 'cancelled_at' => Carbon::now(), 'subject' => null])->save();
        $this->audit($request, 'cancelled');
        $this->events->dispatch(new ErasureCancelled($target, $request->id, $request->pseudonym));

        return $request;
    }

    public function find(DataSubject $target, mixed $id): ?ErasureRequest
    {
        if (! Pseudonyms::isId($id)) {
            return null;
        }
        $request = ErasureRequest::query()->find($id);

        return $request !== null && hash_equals($request->pseudonym, $this->pseudonym($target)) ? $request : null;
    }

    public function latest(DataSubject $target): ?ErasureRequest
    {
        return ErasureRequest::query()->where('pseudonym', $this->pseudonym($target))
            ->orderByDesc('requested_at')->first();
    }

    /**
     * Runs a request whose grace period has ended (or any runnable one, with
     * `$force`). Pending-but-not-due, cancelled and executed requests are
     * returned untouched. Null for an unknown id.
     */
    public function execute(string $id, bool $force = false): ?ErasureRequest
    {
        $request = ErasureRequest::query()->find($id);
        if ($request === null || in_array($request->status, [ErasureRequest::CANCELLED, ErasureRequest::EXECUTED], true)) {
            return $request;
        }
        if ($request->status === ErasureRequest::PENDING && ! $force && $request->due_at->greaterThan(Carbon::now())) {
            return $request;
        }
        if ($request->subject === null) {
            return $request;
        }
        $subject = $this->subjectOf($request);
        $keys = $this->rights->keysFor($subject);

        $request->forceFill(['status' => ErasureRequest::EXECUTING])->save();
        $this->audit($request, 'executing');
        $results = $request->results ?? [];
        $db = $this->app->make('db')->connection();

        foreach ($this->rights->registrants() as $registrant) {
            $key = $registrant->key();
            if (($results[$key]['status'] ?? null) === 'done') {
                continue;
            }
            $version = substr($registrant->version(), 0, 64);
            $plan = new ErasurePlan($request->pseudonym);
            try {
                $db->transaction(static fn () => $registrant->erase($keys, $plan));
                $results[$key] = ['status' => 'done', 'version' => $version, ...$plan->toArray()];
                $this->audit($request, 'registrant_executed', $key, $version, $plan->toArray());
            } catch (Throwable $e) {
                Reporter::report($this->app, $e);
                $results[$key] = ['status' => 'failed', 'version' => $version, 'deleted' => [], 'anonymised' => [], 'retained' => []];
                $this->audit($request, 'registrant_failed', $key, $version, null, mb_substr($e::class.': '.$e->getMessage(), 0, 1000));
            }
            // Saved after each registrant, so a crash part-way resumes where it stopped.
            $request->forceFill(['results' => $results])->save();
        }

        $done = array_filter($results, static fn (array $r): bool => $r['status'] === 'done');
        $complete = count($done) === count($results) && count($results) >= count($this->rights->registrants());
        $status = $complete ? ErasureRequest::EXECUTED : ErasureRequest::PARTIALLY_EXECUTED;
        $request->forceFill([
            'status' => $status,
            'executed_at' => Carbon::now(),
            'subject' => $complete ? null : $request->subject,
        ])->save();
        $this->audit($request, $status);
        $this->events->dispatch(new ErasureExecuted($subject, $request->id, $request->pseudonym, $status));

        return $request;
    }

    /** @return list<ErasureRequest> pending requests whose grace period has ended */
    public function due(): array
    {
        return ErasureRequest::query()->where('status', ErasureRequest::PENDING)
            ->where('due_at', '<=', Carbon::now())->orderBy('due_at')->get()->all();
    }

    /** @return list<ErasureRequest> requests that stopped part-way: partially executed, or interrupted while executing */
    public function unfinished(): array
    {
        return ErasureRequest::query()->whereIn('status', [ErasureRequest::PARTIALLY_EXECUTED, ErasureRequest::EXECUTING])
            ->orderBy('requested_at')->get()->all();
    }

    /** @return array<string, int> */
    public function holdingsFor(ErasureRequest $request): array
    {
        return $request->subject === null ? [] : $this->rights->holdings($this->subjectOf($request));
    }

    /**
     * The protocol's ErasureView. A failure's detail stays in the audit trail.
     *
     * @return array<string, mixed>
     */
    public function view(ErasureRequest $request): array
    {
        $results = $request->results ?? [];
        $registrants = [];
        $keys = array_map(static fn (DataRegistrant $r): string => $r->key(), $this->rights->registrants());
        foreach (array_values(array_unique([...$keys, ...array_keys($results)])) as $key) {
            $result = $results[$key] ?? null;
            $registrants[] = [
                'key' => $key,
                'status' => $result['status'] ?? 'waiting',
                'deleted' => (object) ($result['deleted'] ?? []),
                'anonymised' => (object) ($result['anonymised'] ?? []),
                'retained' => array_values($result['retained'] ?? []),
            ];
        }

        return [
            'id' => $request->id,
            'status' => $request->status,
            'requestedAt' => self::iso($request->requested_at),
            'dueAt' => self::iso($request->due_at),
            'cancelledAt' => self::iso($request->cancelled_at),
            'executedAt' => in_array($request->status, [ErasureRequest::EXECUTED, ErasureRequest::PARTIALLY_EXECUTED], true) ? self::iso($request->executed_at) : null,
            'registrants' => $registrants,
        ];
    }

    /** @throws DataRightsRefused when no usable erasure key is set */
    public function pseudonym(DataSubject $subject): string
    {
        $key = $this->rights->config()->erasureKey() ?? throw new DataRightsRefused('erasure_not_configured', 503);

        return Pseudonyms::erasure($subject, $key);
    }

    /** @param array<string, mixed>|null $counts */
    private function audit(ErasureRequest $request, string $event, ?string $registrant = null, ?string $version = null, ?array $counts = null, ?string $error = null): void
    {
        ErasureRecord::query()->create([
            'request_id' => $request->id,
            'pseudonym' => $request->pseudonym,
            'event' => $event,
            'registrant' => $registrant,
            'registrant_version' => $version,
            'counts' => $counts,
            'error' => $error,
        ]);
    }

    private function subjectOf(ErasureRequest $request): DataSubject
    {
        $data = json_decode($this->encrypter()->decryptString((string) $request->subject), true, flags: JSON_THROW_ON_ERROR);

        return DataSubject::of((string) $data['type'], (string) $data['id']);
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
