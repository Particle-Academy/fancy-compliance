<?php

declare(strict_types=1);

use FancyCompliance\Console\ErasureDueCommand;
use FancyCompliance\DataRights\DataSubject;
use FancyCompliance\DataRights\ErasurePlan;
use FancyCompliance\DataRights\SubjectKeys;
use FancyCompliance\Events\ErasureCancelled;
use FancyCompliance\Events\ErasureExecuted;
use FancyCompliance\Events\ErasureRequested;
use FancyCompliance\Facades\DataRights;
use FancyCompliance\LedgerIsAppendOnly;
use FancyCompliance\Models\ConsentRecord;
use FancyCompliance\Models\ErasureRecord;
use FancyCompliance\Tests\Support\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Phase 4 of CC-fancy-0038: staged deletion. A request waits out a grace
 * period (default 30 days, 0 allowed) and can be cancelled; then each
 * registrant deletes, anonymises or retains-with-reason, and an append-only
 * audit trail names the subject only by a keyed-hash pseudonym.
 */

const ERASURE_KEY = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

function postErasure(User $user, array $body = []): array
{
    return http('POST', '/fancy-compliance/erasure', $body, [], $user);
}

/** @return array{0: int, 1: string} */
function runErasureDue(array $input = []): array
{
    $command = app(ErasureDueCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;
    $code = $command->run(new ArrayInput($input), $output);

    return [$code, $output->fetch()];
}

function pseudonymOf(User $user): string
{
    return hash_hmac('sha256', 'user'."\x1F".$user->id, ERASURE_KEY);
}

/** A host table of its own, with a registrant that deletes, anonymises and retains. */
function hostNotes(): void
{
    Schema::create('notes', function (Blueprint $table): void {
        $table->id();
        $table->string('owner')->nullable();
        $table->string('kind');
    });
    DataRights::register(registrant('app.notes', erase: function (SubjectKeys $keys, ErasurePlan $plan): void {
        $owner = (string) $keys->get('user');
        $plan->delete('notes', DB::table('notes')->where('owner', $owner)->where('kind', 'private')->delete());
        $plan->anonymise('notes', DB::table('notes')->where('owner', $owner)->where('kind', 'public')->update(['owner' => null]));
        $plan->retain('notes', DB::table('notes')->where('owner', $owner)->where('kind', 'invoice')->count(), 'Invoices are kept for tax law.');
    }, holds: 3));
}

function seedNotes(User $user): void
{
    foreach (['private', 'public', 'invoice'] as $kind) {
        DB::table('notes')->insert(['owner' => (string) $user->id, 'kind' => $kind]);
    }
}

/** @return array<string, int> rows per table */
function rowCounts(): array
{
    $counts = [];
    foreach (['fancy_compliance_consents', 'fancy_compliance_exports', 'notes'] as $table) {
        $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : -1;
    }

    return $counts;
}

it('serves no erasure route until the host registers a subject resolver', function (): void {
    complianceApp();

    expect(http('POST', '/fancy-compliance/erasure', [], [], makeUser())['status'])->toBe(404);
});

it('refuses an erasure with no subject', function (): void {
    rightsApp();

    expect(http('POST', '/fancy-compliance/erasure', [])['status'])->toBe(401)
        ->and(DB::table('fancy_compliance_erasure_requests')->count())->toBe(0);
});

it('refuses to record anything without an erasure key, or with a short one', function (mixed $key): void {
    rightsApp(['fancy-compliance.data_rights.erasure.key' => $key]);

    $response = postErasure(makeUser());

    expect($response['status'])->toBe(503)
        ->and($response['body']['error'])->toBe('erasure_not_configured')
        ->and(DB::table('fancy_compliance_erasure_requests')->count())->toBe(0)
        ->and(DB::table('fancy_compliance_erasures')->count())->toBe(0);
})->with(['missing' => [null], 'short' => ['too-short'], 'short base64' => ['base64:'.base64_encode('abc')]]);

it('records a pending request due after the grace period, naming the subject only by pseudonym', function (): void {
    rightsApp();
    $ada = makeUser();

    $response = postErasure($ada);

    expect($response['status'])->toBe(202)
        ->and($response['body']['erasure'])->toMatchArray([
            'status' => 'pending',
            'requestedAt' => '2026-11-05T12:00:00Z',
            'dueAt' => '2026-12-05T12:00:00Z',
            'cancelledAt' => null,
            'executedAt' => null,
        ]);
    $audit = DB::table('fancy_compliance_erasures')->get()->all();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->event)->toBe('requested')
        ->and($audit[0]->pseudonym)->toBe(pseudonymOf($ada));
    $request = (array) DB::table('fancy_compliance_erasure_requests')->first();
    expect($request['pseudonym'])->toBe(pseudonymOf($ada));
    foreach ($request as $column => $value) {
        expect((string) $value)->not->toBe((string) $ada->id, "column {$column} holds the raw subject id");
        expect((string) $value)->not->toContain('"id":"'.$ada->id.'"');
    }
});

it('uses the configured grace period, and the view says so', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 7]);
    $ada = makeUser();

    expect(postErasure($ada)['body']['erasure']['dueAt'])->toBe('2026-11-12T12:00:00Z')
        ->and(http('GET', '/fancy-compliance/erasure', null, [], $ada)['body']['graceDays'])->toBe(7);
});

it('fails closed on a grace period that is not a whole number of days, 0 or more', function (mixed $days): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => $days]);

    expect(postErasure(makeUser())['status'])->toBe(500)
        ->and(DB::table('fancy_compliance_erasure_requests')->count())->toBe(0);
})->with([[-1], ['thirty'], [1.5], [null]]);

it('answers a second request with the pending one', function (): void {
    rightsApp();
    $ada = makeUser();
    $first = postErasure($ada)['body']['erasure'];

    $second = postErasure($ada);

    expect($second['status'])->toBe(409)
        ->and($second['body']['error'])->toBe('erasure_pending')
        ->and($second['body']['erasure']['id'])->toBe($first['id'])
        ->and(DB::table('fancy_compliance_erasure_requests')->count())->toBe(1);
});

it('limits erasure requests per day', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.per_day' => 2]);
    $ada = makeUser();
    foreach ([1, 2] as $n) {
        $id = postErasure($ada)['body']['erasure']['id'];
        http('POST', "/fancy-compliance/erasure/{$id}/cancel", [], [], $ada);
    }

    $third = postErasure($ada);

    expect($third['status'])->toBe(429)
        ->and($third['body']['error'])->toBe('rate_limited')
        ->and($third['body']['retryAfter'])->toBe(86400);
});

it('cancels during the grace period and leaves every row untouched', function (): void {
    rightsApp();
    hostNotes();
    $ada = makeUser();
    seedConsents($ada);
    seedNotes($ada);
    $cancelled = [];
    app('events')->listen(ErasureCancelled::class, function ($e) use (&$cancelled): void {
        $cancelled[] = $e;
    });
    $id = postErasure($ada)['body']['erasure']['id'];
    $before = rowCounts();
    $ledger = ledgerRows();

    $response = http('POST', "/fancy-compliance/erasure/{$id}/cancel", [], [], $ada);
    Carbon::setTestNow('2027-01-01 00:00:00');
    runErasureDue();

    expect($response['status'])->toBe(200)
        ->and($response['body']['erasure']['status'])->toBe('cancelled')
        ->and($response['body']['erasure']['cancelledAt'])->toBe('2026-11-05T12:00:00Z')
        ->and(rowCounts())->toBe($before)
        ->and(ledgerRows())->toEqual($ledger)
        ->and(DB::table('notes')->whereNull('owner')->count())->toBe(0)
        ->and($cancelled)->toHaveCount(1)
        ->and(DB::table('fancy_compliance_erasure_requests')->value('subject'))->toBeNull()
        ->and(DB::table('fancy_compliance_erasures')->pluck('event')->all())->toBe(['requested', 'cancelled']);
});

it('cancels only a pending request, and only the subject\'s own', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    $ada = makeUser('Ada');
    $bob = makeUser('Bob');
    $id = postErasure($ada)['body']['erasure']['id'];

    expect(http('POST', "/fancy-compliance/erasure/{$id}/cancel", [], [], $bob)['status'])->toBe(404)
        ->and(http('POST', "/fancy-compliance/erasure/{$id}/cancel", [], [], $ada)['status'])->toBe(409)
        ->and(http('POST', '/fancy-compliance/erasure/not-an-id/cancel', [], [], $ada)['status'])->toBe(404)
        ->and(http('GET', "/fancy-compliance/erasure/{$id}", null, [], $bob)['status'])->toBe(404);
});

it('executes nothing before the request is due', function (): void {
    rightsApp();
    hostNotes();
    $ada = makeUser();
    seedConsents($ada);
    seedNotes($ada);
    postErasure($ada);
    $before = rowCounts();

    Carbon::setTestNow('2026-12-05 11:59:59');
    [$code] = runErasureDue();

    expect($code)->toBe(0)
        ->and(rowCounts())->toBe($before)
        ->and(DB::table('fancy_compliance_erasure_requests')->value('status'))->toBe('pending');
});

it('executes a due request: each registrant deletes, anonymises and retains exactly what it declared', function (): void {
    rightsApp();
    hostNotes();
    $ada = makeUser('Ada');
    $bob = makeUser('Bob');
    seedConsents($ada);
    seedNotes($ada);
    seedNotes($bob);
    makeRequest('POST', '/', [], $bob);
    consent()->record(['marketing'], 'banner');
    $executed = [];
    app('events')->listen(ErasureExecuted::class, function ($e) use (&$executed): void {
        $executed[] = $e;
    });
    $id = postErasure($ada)['body']['erasure']['id'];

    Carbon::setTestNow('2026-12-05 12:00:00');
    [$code, $out] = runErasureDue();

    expect($code)->toBe(0)->and($out)->toContain($id);
    // The host's table: one deleted, one anonymised, one kept. Bob untouched.
    expect(DB::table('notes')->where('owner', (string) $ada->id)->pluck('kind')->all())->toBe(['invoice'])
        ->and(DB::table('notes')->whereNull('owner')->count())->toBe(1)
        ->and(DB::table('notes')->where('owner', (string) $bob->id)->count())->toBe(3);
    // The ledger: Ada's rows kept, re-keyed to the pseudonym; Bob's untouched.
    expect(DB::table('fancy_compliance_consents')->where('subject_type', 'user')->where('subject_id', (string) $ada->id)->count())->toBe(0)
        ->and(DB::table('fancy_compliance_consents')->where('subject_type', 'erased')->where('subject_id', pseudonymOf($ada))->count())->toBe(2)
        ->and(DB::table('fancy_compliance_consents')->where('subject_id', (string) $bob->id)->count())->toBe(1);
    makeRequest('GET', '/', [], $ada);
    expect(consent()->state()->policyVersion)->toBeNull();

    $view = http('GET', "/fancy-compliance/erasure/{$id}", null, [], $ada)['body']['erasure'];
    $byKey = array_column($view['registrants'], null, 'key');
    expect($view['status'])->toBe('executed')
        ->and($view['executedAt'])->toBe('2026-12-05T12:00:00Z')
        ->and($byKey['app.notes'])->toBe([
            'key' => 'app.notes',
            'status' => 'done',
            'deleted' => ['notes' => 1],
            'anonymised' => ['notes' => 1],
            'retained' => [['table' => 'notes', 'rows' => 1, 'reason' => 'Invoices are kept for tax law.', 'rekeyed' => false]],
        ])
        ->and($byKey['fancy-compliance.consents']['retained'][0])->toMatchArray(['table' => 'fancy_compliance_consents', 'rows' => 2, 'rekeyed' => true])
        ->and($executed)->toHaveCount(1)
        ->and($executed[0]->status)->toBe('executed')
        ->and(DB::table('fancy_compliance_erasure_requests')->value('subject'))->toBeNull();

    $audit = DB::table('fancy_compliance_erasures')->orderBy('id')->get();
    expect($audit->pluck('event')->all())->toBe(['requested', 'executing', 'registrant_executed', 'registrant_executed', 'registrant_executed', 'executed'])
        ->and($audit->pluck('pseudonym')->unique()->all())->toBe([pseudonymOf($ada)])
        ->and($audit->where('registrant', 'app.notes')->first()->registrant_version)->toBe('9.9.9');
    foreach ($audit as $row) {
        expect(json_encode($row))->not->toContain('"'.$ada->id.'"');
    }
});

it('re-keys the guest rows a login linked, so the guest cookie no longer leads to the person', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    $ada = makeUser();
    $guest = http('POST', '/fancy-compliance/consent', ['granted' => ['analytics'], 'source' => 'banner']);
    $token = $guest['cookies'][GUEST_COOKIE]->getValue();
    makeRequest('POST', '/', [GUEST_COOKIE => $token], $ada);
    consent()->linkGuestToUser(app('request'), $ada);

    postErasure($ada);

    $hash = hash('sha256', $token);
    expect(DB::table('fancy_compliance_consents')->where('subject_id', $hash)->count())->toBe(0)
        ->and(DB::table('fancy_compliance_consents')->where('linked_from', $hash)->count())->toBe(0)
        ->and(DB::table('fancy_compliance_consents')->where('subject_id', pseudonymOf($ada))->count())->toBe(2);
});

it('deletes the ledger rows instead when the host chooses it', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0, 'fancy-compliance.data_rights.erasure.consents' => 'delete']);
    $ada = makeUser();
    seedConsents($ada);

    $view = postErasure($ada)['body']['erasure'];

    $byKey = array_column($view['registrants'], null, 'key');
    expect(DB::table('fancy_compliance_consents')->count())->toBe(0)
        ->and($byKey['fancy-compliance.consents']['deleted'])->toBe(['fancy_compliance_consents' => 2])
        ->and($byKey['fancy-compliance.consents']['retained'])->toBe([]);
});

it('fails closed on an unknown ledger erasure mode', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0, 'fancy-compliance.data_rights.erasure.consents' => 'shred']);
    $ada = makeUser();
    seedConsents($ada);

    $view = postErasure($ada)['body']['erasure'];

    expect($view['status'])->toBe('partially_executed')
        ->and(DB::table('fancy_compliance_consents')->where('subject_id', (string) $ada->id)->count())->toBe(2);
});

it('keeps the ledger append-only for everything but erasure and retention', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    $ada = makeUser();
    seedConsents($ada);
    postErasure($ada);

    $row = ConsentRecord::query()->firstOrFail();
    expect(fn () => $row->update(['source' => 'api']))->toThrow(LedgerIsAppendOnly::class)
        ->and(fn () => $row->delete())->toThrow(LedgerIsAppendOnly::class);
});

it('keeps the erasure audit trail append-only', function (): void {
    rightsApp();
    postErasure(makeUser());

    $row = ErasureRecord::query()->firstOrFail();
    expect(fn () => $row->update(['event' => 'cancelled']))->toThrow(LedgerIsAppendOnly::class)
        ->and(fn () => $row->delete())->toThrow(LedgerIsAppendOnly::class);
});

it('keeps a hash-chained log unchanged, records why, and the chain still verifies', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    Schema::create('audit_chain', function (Blueprint $table): void {
        $table->id();
        $table->string('actor');
        $table->string('hash', 64);
    });
    $ada = makeUser();
    $previous = '';
    foreach (['a', 'b', 'c'] as $n) {
        $previous = hash('sha256', $previous.$ada->id.$n);
        DB::table('audit_chain')->insert(['actor' => (string) $ada->id, 'hash' => $previous]);
    }
    $verify = function () use ($ada): bool {
        $previous = '';
        foreach (DB::table('audit_chain')->orderBy('id')->get() as $i => $row) {
            $previous = hash('sha256', $previous.$row->actor.['a', 'b', 'c'][$i]);
            if (! hash_equals($previous, $row->hash)) {
                return false;
            }
        }

        return true;
    };
    $snapshot = DB::table('audit_chain')->get()->all();
    DataRights::register(registrant('app.chain', erase: fn (SubjectKeys $k, ErasurePlan $p) => $p->retain(
        'audit_chain',
        DB::table('audit_chain')->where('actor', (string) $k->get('user'))->count(),
        'Hash-chained audit log: rewriting a row breaks its verification.',
    )));

    $view = postErasure($ada)['body']['erasure'];

    $byKey = array_column($view['registrants'], null, 'key');
    expect($verify())->toBeTrue()
        ->and(DB::table('audit_chain')->get()->all())->toEqual($snapshot)
        ->and($byKey['app.chain']['retained'])->toBe([['table' => 'audit_chain', 'rows' => 3, 'reason' => 'Hash-chained audit log: rewriting a row breaks its verification.', 'rekeyed' => false]]);
});

it('is a no-op when an executed request runs again', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    hostNotes();
    $ada = makeUser();
    seedConsents($ada);
    seedNotes($ada);
    $id = postErasure($ada)['body']['erasure']['id'];
    $counts = rowCounts();
    $audit = DB::table('fancy_compliance_erasures')->count();

    DataRights::executeErasure($id);
    runErasureDue(['--retry' => true]);

    expect(rowCounts())->toBe($counts)
        ->and(DB::table('fancy_compliance_erasures')->count())->toBe($audit);
});

it('leaves a request partially executed when a registrant fails, and resumes it without repeating the rest', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    hostNotes();
    $ada = makeUser();
    seedConsents($ada);
    seedNotes($ada);
    $broken = true;
    $runs = 0;
    DataRights::register(registrant('app.flaky', erase: function (SubjectKeys $k, ErasurePlan $p) use (&$broken, &$runs): void {
        $runs++;
        DB::table('notes')->insert(['owner' => 'flaky-partial-write', 'kind' => 'x']);
        if ($broken) {
            throw new RuntimeException('search index unreachable');
        }
        $p->delete('search_index', 4);
    }));

    $view = postErasure($ada)['body']['erasure'];
    $byKey = array_column($view['registrants'], null, 'key');

    expect($view['status'])->toBe('partially_executed')
        ->and($byKey['app.flaky']['status'])->toBe('failed')
        ->and($byKey['app.notes']['status'])->toBe('done')
        ->and(DB::table('notes')->where('owner', 'flaky-partial-write')->count())->toBe(0) // rolled back
        ->and(json_encode($view))->not->toContain('search index unreachable')
        ->and(DB::table('fancy_compliance_erasures')->where('event', 'registrant_failed')->value('error'))->toContain('search index unreachable')
        ->and(DB::table('fancy_compliance_erasure_requests')->value('subject'))->not->toBeNull();

    [$code] = runErasureDue();
    expect($code)->toBe(1); // still partially executed, and not retried without --retry
    expect($runs)->toBe(1);

    $broken = false;
    $notesAudit = DB::table('fancy_compliance_erasures')->where('registrant', 'app.notes')->count();
    [$code] = runErasureDue(['--retry' => true]);

    $view = http('GET', '/fancy-compliance/erasure/'.$view['id'], null, [], $ada)['body']['erasure'];
    expect($code)->toBe(0)
        ->and($view['status'])->toBe('executed')
        ->and($runs)->toBe(2)
        ->and(DB::table('fancy_compliance_erasures')->where('registrant', 'app.notes')->count())->toBe($notesAudit)
        ->and(DB::table('fancy_compliance_erasure_requests')->value('subject'))->toBeNull();
});

it('executes at once with a grace period of 0', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    $ada = makeUser();
    seedConsents($ada);

    $response = postErasure($ada);

    expect($response['status'])->toBe(202)
        ->and($response['body']['erasure']['status'])->toBe('executed')
        ->and(DB::table('fancy_compliance_consents')->where('subject_type', 'user')->count())->toBe(0);
});

it('deletes the subject\'s exports, files included', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    $ada = makeUser('Ada');
    $bob = makeUser('Bob');
    http('POST', '/fancy-compliance/exports', [], [], $ada);
    http('POST', '/fancy-compliance/exports', [], [], $bob);
    expect(diskFiles())->toHaveCount(2);

    $view = postErasure($ada)['body']['erasure'];

    $byKey = array_column($view['registrants'], null, 'key');
    expect(DB::table('fancy_compliance_exports')->count())->toBe(1)
        ->and(diskFiles())->toHaveCount(1)
        ->and($byKey['fancy-compliance.exports']['deleted'])->toBe(['fancy_compliance_exports' => 1]);
});

it('lets the host block a request with a reason shown to the member', function (): void {
    rightsApp();
    DataRights::blockErasure(fn (DataSubject $s): ?string => 'You have an unpaid invoice.');

    $response = postErasure(makeUser());

    expect($response['status'])->toBe(409)
        ->and($response['body'])->toBe(['error' => 'erasure_blocked', 'reason' => 'You have an unpaid invoice.'])
        ->and(DB::table('fancy_compliance_erasure_requests')->count())->toBe(0);
});

it('asks the authoriser, and a refusal is 403 that writes nothing', function (): void {
    rightsApp();
    DataRights::authorize(fn (DataSubject $actor, DataSubject $target, string $right): bool => $right !== 'erase');

    expect(postErasure(makeUser())['status'])->toBe(403)
        ->and(DB::table('fancy_compliance_erasure_requests')->count())->toBe(0);
});

it('lets staff request an erasure for a member only through the host authoriser', function (): void {
    rightsApp();
    $ada = makeUser('Ada');
    $staff = makeUser('Staff');
    DataRights::authorize(fn (DataSubject $actor, DataSubject $target, string $right): bool => $actor->equals($target) || $actor->id === (string) $staff->id);

    $view = postErasure($staff, ['subject_type' => 'user', 'subject_id' => (string) $ada->id])['body']['erasure'];

    expect(DB::table('fancy_compliance_erasure_requests')->value('pseudonym'))->toBe(pseudonymOf($ada))
        ->and(http('GET', '/fancy-compliance/erasure', null, [], $ada)['body']['erasure']['id'])->toBe($view['id']);
});

it('tells the host when a request is made, so it can lock the account if it wants', function (): void {
    rightsApp();
    $hooked = [];
    $events = [];
    DataRights::onErasureRequested(function (DataSubject $subject, string $id) use (&$hooked): void {
        $hooked[] = [$subject->id, $id];
    });
    app('events')->listen(ErasureRequested::class, function ($e) use (&$events): void {
        $events[] = $e;
    });
    $ada = makeUser();

    $id = postErasure($ada)['body']['erasure']['id'];

    expect($hooked)->toBe([[(string) $ada->id, $id]])
        ->and($events)->toHaveCount(1)
        ->and($events[0]->pseudonym)->toBe(pseudonymOf($ada));
});

it('lists due requests on a dry run and executes none', function (): void {
    rightsApp();
    $ada = makeUser();
    seedConsents($ada);
    $id = postErasure($ada)['body']['erasure']['id'];
    Carbon::setTestNow('2026-12-06 00:00:00');

    [$code, $out] = runErasureDue(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain($id)
        ->and($out)->toContain('fancy-compliance.consents: 2')
        ->and(DB::table('fancy_compliance_erasure_requests')->value('status'))->toBe('pending')
        ->and(DB::table('fancy_compliance_consents')->where('subject_type', 'user')->count())->toBe(2);
});

it('reports what each registrant holds for a subject', function (): void {
    rightsApp();
    hostNotes();
    $ada = makeUser();
    seedConsents($ada);

    expect(DataRights::holdings(DataSubject::of('user', $ada->id)))->toBe([
        'fancy-compliance.consents' => 2,
        'fancy-compliance.exports' => 0,
        'app.notes' => 3,
    ]);
});

it('shows the latest request and the grace period, or null', function (): void {
    rightsApp();
    $ada = makeUser();

    expect(http('GET', '/fancy-compliance/erasure', null, [], $ada)['body'])->toBe(['erasure' => null, 'graceDays' => 30]);

    $id = postErasure($ada)['body']['erasure']['id'];
    expect(http('GET', '/fancy-compliance/erasure', null, [], $ada)['body']['erasure']['id'])->toBe($id);
});

it('refuses a duplicate or malformed registrant key', function (): void {
    rightsApp();
    DataRights::register(registrant('app.notes'));

    expect(fn () => DataRights::register(registrant('app.notes')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DataRights::register(registrant('App Notes')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DataRights::register(registrant('fancy-compliance.consents')))->toThrow(InvalidArgumentException::class);
});

it('rejects a retain with no reason, and a negative count', function (): void {
    rightsApp(['fancy-compliance.data_rights.erasure.grace_days' => 0]);
    DataRights::register(registrant('app.lazy', erase: fn (SubjectKeys $k, ErasurePlan $p) => $p->retain('t', 1, '   ')));
    DataRights::register(registrant('app.negative', erase: fn (SubjectKeys $k, ErasurePlan $p) => $p->delete('t', -1)));

    $byKey = array_column(postErasure(makeUser())['body']['erasure']['registrants'], null, 'key');

    expect($byKey['app.lazy']['status'])->toBe('failed')
        ->and($byKey['app.negative']['status'])->toBe('failed');
});
