<?php

declare(strict_types=1);

use FancyCompliance\DataRights\DataSubject;
use FancyCompliance\DataRights\ExportWriter;
use FancyCompliance\DataRights\SubjectKeys;
use FancyCompliance\Events\ExportDownloaded;
use FancyCompliance\Events\ExportFailed;
use FancyCompliance\Events\ExportReady;
use FancyCompliance\Events\ExportRequested;
use FancyCompliance\Facades\DataRights;
use FancyCompliance\Jobs\BuildExport;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Phase 3 of CC-fancy-0038: a subject's own data, exported as JSON or ZIP,
 * only to the authenticated subject, through a single-use download token.
 * The contract is docs/data-rights-protocol.md.
 */

function postExport(FancyCompliance\Tests\Support\User $user, array $body = []): array
{
    return http('POST', '/fancy-compliance/exports', $body, [], $user);
}

/** The download URL of a ready export view, as a path with its query. */
function downloadPath(array $view): string
{
    expect($view['download'])->toBeArray();

    return $view['download']['url'];
}

it('serves no export route until the host registers a subject resolver', function (): void {
    complianceApp();
    $ada = makeUser();

    expect(http('POST', '/fancy-compliance/exports', [], [], $ada)['status'])->toBe(404)
        ->and(http('GET', '/fancy-compliance/exports', null, [], $ada)['body'])->toBe(['error' => 'data_rights_not_configured']);
});

it('refuses an export with no subject: there is no unauthenticated path', function (): void {
    rightsApp();

    expect(http('POST', '/fancy-compliance/exports', [])['status'])->toBe(401)
        ->and(http('GET', '/fancy-compliance/exports')['status'])->toBe(401)
        ->and(DB::table('fancy_compliance_exports')->count())->toBe(0);
});

it('builds a JSON export of the subject\'s own consent history', function (): void {
    rightsApp();
    $ada = makeUser('Ada');
    $bob = makeUser('Bob');
    seedConsents($ada);
    makeRequest('POST', '/', [], $bob);
    consent()->record(['marketing'], 'banner');

    $response = postExport($ada);

    expect($response['status'])->toBe(202)
        ->and($response['body']['export']['status'])->toBe('ready')
        ->and($response['body']['export']['format'])->toBe('json')
        ->and($response['body']['export']['error'])->toBeNull();

    $file = rawHttp('GET', downloadPath($response['body']['export']), $ada);
    expect($file['status'])->toBe(200)
        ->and($file['headers']->get('Content-Type'))->toContain('application/json')
        ->and($file['headers']->get('Content-Disposition'))->toContain('attachment')
        ->and($file['headers']->get('Cache-Control'))->toContain('no-store');

    $export = json_decode($file['content'], true, flags: JSON_THROW_ON_ERROR);
    expect($export['format'])->toBe('fancy-compliance.export')
        ->and($export['version'])->toBe(1)
        ->and($export['subject'])->toBe(['type' => 'user', 'id' => (string) $ada->id])
        ->and($export['generated_at'])->toBe('2026-11-05T12:00:00Z')
        ->and(array_column($export['sections']['fancy-compliance.consents']['consents'], 'action'))->toBe(['withdrawal', 'decision'])
        ->and($file['content'])->not->toContain('"marketing"],"refused"');
});

it('never puts a guest hash, a subject key or a token in an export', function (): void {
    rightsApp();
    $ada = makeUser();
    $guest = http('POST', '/fancy-compliance/consent', ['granted' => ['analytics'], 'source' => 'banner']);
    $token = $guest['cookies'][GUEST_COOKIE]->getValue();
    makeRequest('POST', '/', [GUEST_COOKIE => $token], $ada);
    consent()->linkGuestToUser(app('request'), $ada);

    $view = postExport($ada)['body']['export'];
    $file = rawHttp('GET', downloadPath($view), $ada)['content'];

    expect($file)->not->toContain(hash('sha256', $token))
        ->and($file)->not->toContain($token)
        ->and($file)->not->toContain((string) DB::table('fancy_compliance_exports')->value('download_token_hash'));
});

it('stores the export encrypted at rest, on the host\'s disk', function (): void {
    rightsApp();
    $ada = makeUser();
    seedConsents($ada);

    postExport($ada);

    $files = diskFiles();
    expect($files)->toHaveCount(1)
        ->and($files[0])->toStartWith('fancy-compliance/exports/');
    $raw = file_get_contents(FancyCompliance\Tests\Support\TestApp::storagePath().'/'.$files[0]);
    expect($raw)->not->toContain('fancy-compliance.export')
        ->and($raw)->not->toContain('withdrawal');
});

it('marks every export response as not cacheable', function (): void {
    rightsApp();
    $ada = makeUser();
    $request = makeRequest('GET', '/fancy-compliance/exports', [], $ada);

    expect(app('router')->dispatch($request)->headers->get('Cache-Control'))->toContain('no-store');
});

it('lists the subject\'s exports, newest first, with formats and what is left today', function (): void {
    rightsApp();
    $ada = makeUser();
    postExport($ada);
    Carbon::setTestNow('2026-11-05 12:05:00');
    postExport($ada, ['format' => 'zip']);

    $list = http('GET', '/fancy-compliance/exports', null, [], $ada)['body'];

    expect(array_column($list['exports'], 'format'))->toBe(['zip', 'json'])
        ->and($list['formats'])->toBe(['json', 'zip'])
        ->and($list['limits'])->toBe(['perDay' => 3, 'remainingToday' => 1]);
});

it('requires the authenticated subject to download: no auth is 401, someone else is 404', function (): void {
    rightsApp();
    $ada = makeUser('Ada');
    $bob = makeUser('Bob');
    $path = downloadPath(postExport($ada)['body']['export']);

    expect(rawHttp('GET', $path)['status'])->toBe(401)
        ->and(rawHttp('GET', $path, $bob)['status'])->toBe(404)
        ->and(rawHttp('GET', $path, $ada)['status'])->toBe(200);
});

it('needs the token as well as the subject: none or a wrong one is 404', function (): void {
    rightsApp();
    $ada = makeUser();
    $view = postExport($ada)['body']['export'];
    $base = '/fancy-compliance/exports/'.$view['id'].'/download';

    expect(rawHttp('GET', $base, $ada)['status'])->toBe(404)
        ->and(rawHttp('GET', $base.'?token='.str_repeat('a', 43), $ada)['status'])->toBe(404)
        ->and(rawHttp('GET', $base.'?token[]=x', $ada)['status'])->toBe(404);
});

it('uses each download token once, and issues a fresh one when the export is read again', function (): void {
    rightsApp();
    $ada = makeUser();
    $view = postExport($ada)['body']['export'];
    $first = downloadPath($view);

    expect(rawHttp('GET', $first, $ada)['status'])->toBe(200)
        ->and(rawHttp('GET', $first, $ada)['status'])->toBe(404);

    $again = http('GET', '/fancy-compliance/exports/'.$view['id'], null, [], $ada)['body']['export'];
    expect(downloadPath($again))->not->toBe($first)
        ->and(rawHttp('GET', downloadPath($again), $ada)['status'])->toBe(200);
});

it('expires an export after its time to live: 410, and the file is deleted', function (): void {
    rightsApp(['fancy-compliance.data_rights.exports.ttl_hours' => 24]);
    $ada = makeUser();
    $view = postExport($ada)['body']['export'];
    expect($view['expiresAt'])->toBe('2026-11-06T12:00:00Z');

    Carbon::setTestNow('2026-11-06 12:00:01');

    expect(rawHttp('GET', downloadPath($view), $ada)['status'])->toBe(410)
        ->and(diskFiles())->toBe([])
        ->and(http('GET', '/fancy-compliance/exports/'.$view['id'], null, [], $ada)['body']['export'])
        ->toMatchArray(['status' => 'expired', 'download' => null]);
});

it('deletes the file after the first download when the host asks for it', function (): void {
    rightsApp(['fancy-compliance.data_rights.exports.delete_after_download' => true]);
    $ada = makeUser();
    $view = postExport($ada)['body']['export'];

    rawHttp('GET', downloadPath($view), $ada);

    expect(diskFiles())->toBe([])
        ->and(http('GET', '/fancy-compliance/exports/'.$view['id'], null, [], $ada)['body']['export'])
        ->toMatchArray(['status' => 'downloaded', 'download' => null]);
});

it('builds a ZIP with a manifest whose checksums match, and carries attached files', function (): void {
    rightsApp();
    $ada = makeUser();
    seedConsents($ada);
    DataRights::register(registrant('app.documents', export: function (SubjectKeys $keys, ExportWriter $w): void {
        $w->json('letters', [['id' => 1, 'to' => $keys->get('user')]], rows: 1);
        $w->file('letter-1.pdf', "%PDF-1.7 fake\n");
    }));

    $view = postExport($ada, ['format' => 'zip'])['body']['export'];
    $file = rawHttp('GET', downloadPath($view), $ada);
    expect($file['headers']->get('Content-Type'))->toContain('application/zip');

    $path = tempnam(sys_get_temp_dir(), 'fczip');
    file_put_contents($path, $file['content']);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['format'])->toBe('fancy-compliance.export')
        ->and($manifest['registrants'])->toContain('fancy-compliance.consents', 'app.documents');
    $paths = array_column($manifest['files'], 'path');
    expect($paths)->toContain('fancy-compliance.consents/consents.json', 'app.documents/letters.json', 'app.documents/files/letter-1.pdf');
    foreach ($manifest['files'] as $entry) {
        $content = $zip->getFromName($entry['path']);
        expect($content)->not->toBeFalse()
            ->and(hash('sha256', (string) $content))->toBe($entry['sha256'])
            ->and(strlen((string) $content))->toBe($entry['bytes']);
    }
    $letters = array_values(array_filter($manifest['files'], fn ($f) => $f['path'] === 'app.documents/letters.json'));
    expect($letters[0]['rows'])->toBe(1)
        ->and($zip->getFromName('app.documents/files/letter-1.pdf'))->toBe("%PDF-1.7 fake\n");
    $zip->close();
    unlink($path);
});

it('names attached files it cannot carry in a JSON export', function (): void {
    rightsApp();
    $ada = makeUser();
    DataRights::register(registrant('app.documents', export: fn (SubjectKeys $k, ExportWriter $w) => $w->file('letter-1.pdf', '12345')));

    $export = json_decode(rawHttp('GET', downloadPath(postExport($ada)['body']['export']), $ada)['content'], true);

    expect($export['files_omitted'])->toBe([['registrant' => 'app.documents', 'name' => 'letter-1.pdf', 'bytes' => 5]]);
});

it('fails the export visibly when a registrant throws, and keeps no partial file', function (): void {
    rightsApp();
    $ada = makeUser();
    $failed = [];
    app('events')->listen(ExportFailed::class, function (ExportFailed $e) use (&$failed): void {
        $failed[] = $e;
    });
    DataRights::register(registrant('app.broken', export: fn () => throw new RuntimeException('database is down')));

    $view = postExport($ada)['body']['export'];

    expect($view['status'])->toBe('failed')
        ->and($view['error'])->toBe('registrant_failed')
        ->and($view['download'])->toBeNull()
        ->and(diskFiles())->toBe([])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->registrant)->toBe('app.broken');
});

it('fails an export past the size cap or the file cap, loudly', function (string $setting, int $value, string $error): void {
    rightsApp(['fancy-compliance.data_rights.exports.'.$setting => $value]);
    $ada = makeUser();
    DataRights::register(registrant('app.big', export: function (SubjectKeys $k, ExportWriter $w): void {
        $w->json('blob', str_repeat('x', 4000));
        $w->json('more', ['a']);
        $w->json('again', ['b']);
    }));

    $view = postExport($ada)['body']['export'];

    expect($view['status'])->toBe('failed')->and($view['error'])->toBe($error);
})->with([
    'bytes' => ['max_bytes', 2000, 'too_large'],
    'files' => ['max_files', 2, 'too_many_files'],
]);

it('rejects a section name that could escape its folder', function (): void {
    rightsApp();
    $ada = makeUser();
    DataRights::register(registrant('app.sneaky', export: fn (SubjectKeys $k, ExportWriter $w) => $w->file('../../etc/passwd', 'x')));

    expect(postExport($ada, ['format' => 'zip'])['body']['export']['error'])->toBe('registrant_failed');
});

it('allows one pending export per subject', function (): void {
    rightsApp(['fancy-compliance.data_rights.exports.queue.enabled' => true]);
    $dispatched = [];
    app()->instance(Dispatcher::class, new class($dispatched) implements Dispatcher
    {
        public function __construct(public array &$jobs) {}

        public function dispatch($command)
        {
            $this->jobs[] = $command;
        }

        public function dispatchSync($command, $handler = null)
        {
            $this->jobs[] = $command;
        }

        public function dispatchNow($command, $handler = null)
        {
            $this->jobs[] = $command;
        }

        public function dispatchAfterResponse($command, $handler = null)
        {
            $this->jobs[] = $command;
        }

        public function chain($jobs = null)
        {
            return null;
        }

        public function hasCommandHandler($command)
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return false;
        }

        public function pipeThrough(array $pipes)
        {
            return $this;
        }

        public function map(array $map)
        {
            return $this;
        }
    });
    $ada = makeUser();

    $first = postExport($ada);
    $second = postExport($ada);

    expect($first['status'])->toBe(202)
        ->and($first['body']['export']['status'])->toBe('pending')
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0])->toBeInstanceOf(BuildExport::class)
        ->and($dispatched[0]->exportId)->toBe($first['body']['export']['id'])
        ->and($second['status'])->toBe(409)
        ->and($second['body']['error'])->toBe('export_in_progress')
        ->and($second['body']['export']['id'])->toBe($first['body']['export']['id']);

    app(BuildExport::class, ['exportId' => $first['body']['export']['id']])->handle(app(FancyCompliance\DataRights\ExportService::class));
    expect(http('GET', '/fancy-compliance/exports/'.$first['body']['export']['id'], null, [], $ada)['body']['export']['status'])->toBe('ready');
});

it('limits exports per day, per subject, and says when to retry', function (): void {
    rightsApp(['fancy-compliance.data_rights.exports.per_day' => 2]);
    $ada = makeUser('Ada');
    $bob = makeUser('Bob');
    postExport($ada);
    Carbon::setTestNow('2026-11-05 13:00:00');
    postExport($ada);

    $third = postExport($ada);
    expect($third['status'])->toBe(429)
        ->and($third['body']['error'])->toBe('rate_limited')
        ->and($third['body']['retryAfter'])->toBe(23 * 3600)
        ->and(postExport($bob)['status'])->toBe(202);

    Carbon::setTestNow('2026-11-06 12:00:01');
    expect(postExport($ada)['status'])->toBe(202);
});

it('is 422 for an unknown format, or ZIP when the host allows only JSON', function (): void {
    rightsApp(['fancy-compliance.data_rights.exports.formats' => ['json']]);
    $ada = makeUser();

    expect(postExport($ada, ['format' => 'xml'])['status'])->toBe(422)
        ->and(postExport($ada, ['format' => ['json']])['status'])->toBe(422)
        ->and(postExport($ada, ['format' => 'zip'])['status'])->toBe(422)
        ->and(http('GET', '/fancy-compliance/exports', null, [], $ada)['body']['formats'])->toBe(['json'])
        ->and(DB::table('fancy_compliance_exports')->count())->toBe(0);
});

it('asks the authoriser, and a refusal is 403 that writes nothing', function (): void {
    rightsApp();
    DataRights::authorize(fn (): bool => false);
    $ada = makeUser();

    expect(postExport($ada)['status'])->toBe(403)
        ->and(DB::table('fancy_compliance_exports')->count())->toBe(0);
});

it('lets staff export a member only when the host authoriser says so', function (): void {
    rightsApp();
    $ada = makeUser('Ada');
    $staff = makeUser('Staff');
    seedConsents($ada);
    DataRights::authorize(fn (DataSubject $actor, DataSubject $target, string $right): bool => $actor->equals($target) || ($actor->id === (string) $staff->id && $right === 'export'));

    $view = postExport($staff, ['subject_type' => 'user', 'subject_id' => (string) $ada->id])['body']['export'];
    $target = '?subject_type=user&subject_id='.$ada->id;
    $export = json_decode(rawHttp('GET', downloadPath($view).'&subject_type=user&subject_id='.$ada->id, $staff)['content'], true);

    expect($export['subject']['id'])->toBe((string) $ada->id)
        ->and($export['sections']['fancy-compliance.consents']['consents'])->toHaveCount(2)
        ->and(postExport($ada, ['subject_type' => 'user', 'subject_id' => (string) $staff->id])['status'])->toBe(403)
        ->and(http('GET', '/fancy-compliance/exports/'.$view['id'], null, [], $ada)['status'])->toBe(200)
        ->and(http('GET', '/fancy-compliance/exports/'.$view['id'].$target, null, [], $ada)['status'])->toBe(200);
});

it('works for a host whose subjects are not users rows: a member keyed by UUID', function (): void {
    complianceApp(['fancy-compliance.data_rights.reauth.required' => false]);
    $ada = makeUser();
    seedConsents($ada);
    $uuid = '7f1c2d4e-0b9a-4c3e-9f00-5a1b2c3d4e5f';
    DataRights::subjects(fn ($r): ?DataSubject => $r->user() ? DataSubject::of('member', $uuid) : null);
    DataRights::resolveFor(fn (DataSubject $s): SubjectKeys => SubjectKeys::of(['user' => $s->id === $uuid ? $ada->id : null]));

    $view = postExport($ada)['body']['export'];
    $export = json_decode(rawHttp('GET', downloadPath($view), $ada)['content'], true);

    expect($export['subject'])->toBe(['type' => 'member', 'id' => $uuid])
        ->and($export['sections']['fancy-compliance.consents']['consents'])->toHaveCount(2);
});

it('dispatches requested, ready and downloaded events', function (): void {
    rightsApp();
    $seen = [];
    foreach ([ExportRequested::class, ExportReady::class, ExportDownloaded::class] as $event) {
        app('events')->listen($event, function (object $e) use (&$seen): void {
            $seen[] = $e::class;
        });
    }
    $ada = makeUser();

    rawHttp('GET', downloadPath(postExport($ada)['body']['export']), $ada);

    expect($seen)->toBe([ExportRequested::class, ExportReady::class, ExportDownloaded::class]);
});

it('stores no subject identifier in the exports table, only a keyed hash', function (): void {
    rightsApp();
    $ada = makeUser();
    postExport($ada);

    $row = (array) DB::table('fancy_compliance_exports')->first();

    expect($row['subject_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($row['subject_hash'])->not->toBe(hash('sha256', 'user'.$ada->id))
        ->and(array_keys($row))->not->toContain('subject_id');
});

it('prunes expired export files and old export records through the retention registry', function (): void {
    rightsApp(['fancy-compliance.data_rights.exports.record_days' => 30]);
    $ada = makeUser();
    postExport($ada);
    Carbon::setTestNow('2026-11-07 12:00:00');
    $fresh = postExport($ada)['body']['export'];

    Carbon::setTestNow('2026-11-07 13:00:00');
    expect(app(FancyCompliance\Retention\RetentionRegistry::class)->get('fancy-compliance.exports')->run(true)->counts)
        ->toBe(['files' => 1, 'records' => 0]);
    app(FancyCompliance\Retention\RetentionRegistry::class)->get('fancy-compliance.exports')->run(false);
    expect(diskFiles())->toHaveCount(1)
        ->and(DB::table('fancy_compliance_exports')->count())->toBe(2);

    Carbon::setTestNow('2026-12-06 13:00:00');
    $outcome = app(FancyCompliance\Retention\RetentionRegistry::class)->get('fancy-compliance.exports')->run(false);
    expect($outcome->counts)->toBe(['files' => 1, 'records' => 1])
        ->and(DB::table('fancy_compliance_exports')->pluck('id')->all())->toBe([$fresh['id']])
        ->and(diskFiles())->toBe([]);
});
