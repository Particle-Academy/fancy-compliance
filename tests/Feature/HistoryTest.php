<?php

declare(strict_types=1);

use FancyCompliance\DataRights\DataSubject;
use FancyCompliance\DataRights\SubjectKeys;
use FancyCompliance\Facades\DataRights;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/*
 * Phase 1 of CC-fancy-0038: a subject's consent history, read through the
 * host's subject resolver and authoriser. Nothing here assumes a `users` table,
 * an `id` column or a Gate ability: the host says who is asking and who may
 * read whose history.
 */

/** The named history route, after the name index is refreshed (names given after add are indexed lazily). */
function historyRoute(): ?Illuminate\Routing\Route
{
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    return $routes->getByName('fancy-compliance.history');
}

/** The host's resolver: the signed-in user, as a `user` data subject. */
function resolveUsers(): void
{
    DataRights::subjects(fn (Request $r): ?DataSubject => $r->user() === null ? null : DataSubject::of('user', $r->user()->getAuthIdentifier()));
}

/** Two decisions for $user, then one for another person, all through the manager. */
function seedTwoPeople(): array
{
    $ada = makeUser('Ada');
    $bob = makeUser('Bob');
    makeRequest('POST', '/', [], $ada);
    consent()->record(['analytics'], 'banner');
    Carbon::setTestNow('2026-11-06 09:30:00');
    makeRequest('POST', '/', [], $ada);
    consent()->withdraw(['analytics'], 'preferences');
    makeRequest('POST', '/', [], $bob);
    consent()->record(['marketing'], 'banner');

    return [$ada, $bob];
}

it('lists the request subject\'s own ledger rows, newest first', function (): void {
    complianceApp();
    [$ada] = seedTwoPeople();
    makeRequest('GET', '/', [], $ada);

    $page = consent()->history();

    expect($page->entries)->toHaveCount(2)
        ->and($page->entries[0]->action)->toBe('withdrawal')
        ->and($page->entries[0]->granted)->toBe(['necessary'])
        ->and($page->entries[0]->refused)->toBe(['analytics', 'marketing'])
        ->and($page->entries[0]->source)->toBe('preferences')
        ->and($page->entries[0]->toArray()['at'])->toBe('2026-11-06T09:30:00Z')
        ->and($page->entries[1]->action)->toBe('decision')
        ->and($page->nextBefore)->toBeNull();
});

it('serves no history route until the host registers a subject resolver', function (): void {
    complianceApp();
    $ada = makeUser();

    $response = http('GET', '/fancy-compliance/history', null, [], $ada);

    expect($response['status'])->toBe(404)
        ->and($response['body'])->toBe(['error' => 'data_rights_not_configured']);
});

it('serves the resolved subject\'s history over HTTP, and only theirs', function (): void {
    complianceApp();
    resolveUsers();
    [$ada] = seedTwoPeople();

    $response = http('GET', '/fancy-compliance/history', null, [], $ada);

    expect($response['status'])->toBe(200)
        ->and(array_column($response['body']['entries'], 'action'))->toBe(['withdrawal', 'decision'])
        ->and($response['body']['entries'][1])->toBe([
            'id' => 1,
            'action' => 'decision',
            'granted' => ['necessary', 'analytics'],
            'refused' => ['marketing'],
            'policyVersion' => '1',
            'source' => 'banner',
            'at' => '2026-11-05T12:00:00Z',
            'linked' => false,
        ])
        ->and($response['body']['nextBefore'])->toBeNull()
        ->and(str_contains($response['raw'], 'marketing"],"refused":["analytics'))->toBeFalse();
});

it('marks history as not cacheable', function (): void {
    complianceApp();
    resolveUsers();
    $ada = makeUser();
    $request = makeRequest('GET', '/fancy-compliance/history', [], $ada);

    $response = app('router')->dispatch($request);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('is 401 for a request with no subject and no guest cookie', function (): void {
    complianceApp();
    resolveUsers();

    expect(http('GET', '/fancy-compliance/history')['status'])->toBe(401);
});

it('lets a guest read the rows of its own cookie, and never shows the guest hash', function (): void {
    complianceApp();
    resolveUsers();
    $first = http('POST', '/fancy-compliance/consent', ['granted' => ['analytics'], 'source' => 'banner']);
    $token = $first['cookies'][GUEST_COOKIE]->getValue();
    http('POST', '/fancy-compliance/consent', ['granted' => [], 'source' => 'banner']); // another guest

    $response = http('GET', '/fancy-compliance/history', null, [GUEST_COOKIE => $token]);

    expect($response['status'])->toBe(200)
        ->and($response['body']['entries'])->toHaveCount(1)
        ->and($response['body']['entries'][0]['granted'])->toBe(['necessary', 'analytics'])
        ->and(str_contains($response['raw'], hash('sha256', $token)))->toBeFalse()
        ->and(str_contains($response['raw'], $token))->toBeFalse();
});

it('does not let a guest name another subject', function (): void {
    complianceApp();
    resolveUsers();
    $token = http('POST', '/fancy-compliance/consent', ['granted' => [], 'source' => 'banner'])['cookies'][GUEST_COOKIE]->getValue();

    $response = http('GET', '/fancy-compliance/history?subject_type=user&subject_id=1', null, [GUEST_COOKIE => $token]);

    expect($response['status'])->toBe(403);
});

it('lets the host turn guest history off', function (): void {
    complianceApp(['fancy-compliance.data_rights.history.guests' => false]);
    resolveUsers();
    $token = http('POST', '/fancy-compliance/consent', ['granted' => [], 'source' => 'banner'])['cookies'][GUEST_COOKIE]->getValue();

    expect(http('GET', '/fancy-compliance/history', null, [GUEST_COOKIE => $token])['status'])->toBe(401);
});

it('shows a login link as linked, without the guest it came from', function (): void {
    complianceApp();
    resolveUsers();
    $token = http('POST', '/fancy-compliance/consent', ['granted' => ['marketing'], 'source' => 'banner'])['cookies'][GUEST_COOKIE]->getValue();
    $ada = makeUser();
    consent()->linkGuestToUser(makeRequest('POST', '/', [GUEST_COOKIE => $token]), $ada);

    $response = http('GET', '/fancy-compliance/history', null, [], $ada);

    expect($response['body']['entries'])->toHaveCount(1)
        ->and($response['body']['entries'][0]['action'])->toBe('link')
        ->and($response['body']['entries'][0]['source'])->toBe('login')
        ->and($response['body']['entries'][0]['linked'])->toBeTrue()
        ->and(str_contains($response['raw'], hash('sha256', $token)))->toBeFalse();
});

it('refuses another subject\'s history with 403 under the default authoriser', function (): void {
    complianceApp();
    resolveUsers();
    [$ada, $bob] = seedTwoPeople();
    $before = count(ledgerRows());

    $response = http('GET', '/fancy-compliance/history?subject_type=user&subject_id='.$bob->id, null, [], $ada);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toBe(['error' => 'forbidden'])
        ->and(ledgerRows())->toHaveCount($before);
});

it('lets staff read a member\'s history when the host authoriser allows it, and asks it with the right', function (): void {
    complianceApp();
    resolveUsers();
    [$ada, $bob] = seedTwoPeople();
    $asked = [];
    DataRights::authorize(function (DataSubject $actor, DataSubject $target, string $right) use (&$asked, $ada): bool {
        $asked[] = [$actor->type.':'.$actor->id, $target->type.':'.$target->id, $right];

        return $actor->id === (string) $ada->id;
    });

    $response = http('GET', '/fancy-compliance/history?subject_type=user&subject_id='.$bob->id, null, [], $ada);

    expect($response['status'])->toBe(200)
        ->and($response['body']['entries'])->toHaveCount(1)
        ->and($response['body']['entries'][0]['granted'])->toBe(['necessary', 'marketing'])
        ->and($asked)->toBe([['user:'.$ada->id, 'user:'.$bob->id, 'history']]);
});

it('asks the authoriser even for the subject\'s own history, and honours a refusal', function (): void {
    complianceApp();
    resolveUsers();
    [$ada] = seedTwoPeople();
    DataRights::authorize(fn (): bool => false);

    expect(http('GET', '/fancy-compliance/history', null, [], $ada)['status'])->toBe(403);
});

it('works for a host whose subject is not a users row, through the key resolver', function (): void {
    complianceApp();
    $ada = makeUser();
    makeRequest('POST', '/', [], $ada);
    consent()->record(['analytics'], 'banner');
    // The host calls its people members, keyed by a UUID; the ledger knows them by auth id.
    DataRights::subjects(fn (Request $r): ?DataSubject => $r->user() === null ? null : DataSubject::of('member', '9b2f6c1e-0d4a-4c8e-b7a5-3e1f2a6d8c90'));
    DataRights::resolveFor(fn (DataSubject $s): SubjectKeys => SubjectKeys::of(['user' => $s->id === '9b2f6c1e-0d4a-4c8e-b7a5-3e1f2a6d8c90' ? $ada->id : null]));

    $response = http('GET', '/fancy-compliance/history', null, [], $ada);

    expect($response['status'])->toBe(200)
        ->and($response['body']['entries'])->toHaveCount(1)
        ->and($response['body']['entries'][0]['granted'])->toBe(['necessary', 'analytics']);
});

it('pages with a limit and a before cursor', function (): void {
    complianceApp();
    resolveUsers();
    $ada = makeUser();
    foreach (range(1, 5) as $i) {
        makeRequest('POST', '/', [], $ada);
        consent()->record($i % 2 === 0 ? ['analytics'] : [], 'banner');
    }

    $first = http('GET', '/fancy-compliance/history?limit=2', null, [], $ada);
    $second = http('GET', '/fancy-compliance/history?limit=2&before='.$first['body']['nextBefore'], null, [], $ada);
    $last = http('GET', '/fancy-compliance/history?limit=2&before='.$second['body']['nextBefore'], null, [], $ada);

    expect(array_column($first['body']['entries'], 'id'))->toBe([5, 4])
        ->and($first['body']['nextBefore'])->toBe(4)
        ->and(array_column($second['body']['entries'], 'id'))->toBe([3, 2])
        ->and(array_column($last['body']['entries'], 'id'))->toBe([1])
        ->and($last['body']['nextBefore'])->toBeNull();
});

it('caps the page size at the configured maximum', function (): void {
    complianceApp(['fancy-compliance.data_rights.history.max_limit' => 3]);
    resolveUsers();
    $ada = makeUser();
    foreach (range(1, 5) as $i) {
        makeRequest('POST', '/', [], $ada);
        consent()->record([], 'banner');
    }

    $response = http('GET', '/fancy-compliance/history?limit=100', null, [], $ada);

    expect($response['body']['entries'])->toHaveCount(3)
        ->and($response['body']['nextBefore'])->toBe(3);
});

it('refuses malformed paging and subject input with 422', function (string $query): void {
    complianceApp();
    resolveUsers();
    $ada = makeUser();

    $response = http('GET', '/fancy-compliance/history?'.$query, null, [], $ada);

    expect($response['status'])->toBe(422);
})->with([
    'limit zero' => ['limit=0'],
    'limit text' => ['limit=ten'],
    'before negative' => ['before=-1'],
    'before array' => ['before[]=1'],
    'type without id' => ['subject_type=user'],
    'id without type' => ['subject_id=1'],
    'bad type' => ['subject_type=User%20Model&subject_id=1'],
    'overlong id' => ['subject_type=user&subject_id='.str_repeat('a', 192)],
]);

it('lets the host turn the history route off', function (): void {
    complianceApp();
    expect(historyRoute())->not->toBeNull();

    complianceApp(['fancy-compliance.data_rights.history.enabled' => false]);

    expect(historyRoute())->toBeNull();
});
