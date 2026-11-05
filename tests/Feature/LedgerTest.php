<?php

declare(strict_types=1);

use FancyCompliance\InvalidConsentInput;
use FancyCompliance\LedgerIsAppendOnly;
use FancyCompliance\Models\ConsentRecord;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => complianceApp());

it('asks a first-time guest for consent and allows only the necessary category', function (): void {
    makeRequest();

    $state = consent()->state();

    expect($state->needsConsent())->toBeTrue()
        ->and(consent()->allows('necessary'))->toBeTrue()
        ->and(consent()->allows('analytics'))->toBeFalse()
        ->and(ledgerRows())->toBe([]);
});

it('records one row per decision with the granted and the refused categories, the policy version and the source', function (): void {
    makeRequest('POST');

    $state = consent()->record(['analytics'], 'banner');

    $rows = ledgerRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->subject_type)->toBe('guest')
        ->and(json_decode($rows[0]->granted, true))->toBe(['necessary', 'analytics'])
        ->and(json_decode($rows[0]->refused, true))->toBe(['marketing'])
        ->and($rows[0]->policy_version)->toBe('1')
        ->and($rows[0]->source)->toBe('banner')
        ->and($rows[0]->action)->toBe('decision')
        ->and($rows[0]->created_at)->toBe('2026-11-05 12:00:00')
        ->and($state->allows('analytics'))->toBeTrue()
        ->and($state->allows('marketing'))->toBeFalse()
        ->and($state->needsConsent())->toBeFalse();
});

it('identifies a guest by a random first-party cookie and stores only its hash', function (): void {
    $request = makeRequest('POST');
    consent()->record([], 'banner');

    $cookie = consent()->pendingGuestCookie($request);
    expect($cookie)->not->toBeNull()
        ->and($cookie->getName())->toBe(GUEST_COOKIE)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getValue())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(ledgerRows()[0]->subject_id)->toBe(hash('sha256', $cookie->getValue()))
        ->and(ledgerRows()[0]->subject_id)->not->toBe($cookie->getValue());

    // The same browser, next request: the cookie finds the decision.
    makeRequest('GET', '/', [GUEST_COOKIE => $cookie->getValue()]);
    expect(consent()->state()->needsConsent())->toBeFalse();
});

it('does not set a cookie before the guest decides', function (): void {
    $request = makeRequest();
    consent()->state();

    expect(consent()->pendingGuestCookie($request))->toBeNull();
});

it('withdraws by appending a new row and never edits the earlier one', function (): void {
    makeRequest('POST');
    consent()->record(['analytics', 'marketing'], 'banner');
    $before = ledgerRows()[0];
    $token = consent()->pendingGuestCookie(app('request'))->getValue();

    Carbon::setTestNow('2026-11-06 09:30:00');
    makeRequest('POST', '/', [GUEST_COOKIE => $token]);
    $state = consent()->withdraw(['analytics'], 'preferences');

    $rows = ledgerRows();
    expect($rows)->toHaveCount(2)
        ->and((array) $rows[0])->toBe((array) $before)
        ->and($rows[1]->action)->toBe('withdrawal')
        ->and($rows[1]->source)->toBe('preferences')
        ->and(json_decode($rows[1]->granted, true))->toBe(['necessary', 'marketing'])
        ->and(json_decode($rows[1]->refused, true))->toBe(['analytics'])
        ->and($rows[1]->created_at)->toBe('2026-11-06 09:30:00')
        ->and($state->allows('analytics'))->toBeFalse()
        ->and($state->allows('marketing'))->toBeTrue();
});

it('withdraws every optional category when none is named', function (): void {
    makeRequest('POST');
    consent()->record(['analytics', 'marketing'], 'banner');

    $state = consent()->withdraw(null, 'preferences');

    expect($state->granted)->toBe(['necessary'])
        ->and($state->refused)->toBe(['analytics', 'marketing'])
        ->and(ledgerRows())->toHaveCount(2);
});

it('takes the current state from the latest row of each subject only', function (): void {
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    $first = consent()->pendingGuestCookie(app('request'))->getValue();

    makeRequest('POST');
    consent()->record([], 'banner');
    $second = consent()->pendingGuestCookie(app('request'))->getValue();

    makeRequest('POST', '/', [GUEST_COOKIE => $first]);
    consent()->record(['marketing'], 'preferences');

    makeRequest('GET', '/', [GUEST_COOKIE => $first]);
    expect(consent()->state()->granted)->toBe(['necessary', 'marketing']);
    makeRequest('GET', '/', [GUEST_COOKIE => $second]);
    expect(consent()->state()->granted)->toBe(['necessary']);
});

it('never lets the necessary category be refused', function (): void {
    makeRequest('POST');
    $state = consent()->record([], 'banner');

    expect($state->allows('necessary'))->toBeTrue()
        ->and($state->granted)->toBe(['necessary'])
        ->and($state->refused)->not->toContain('necessary');

    expect(fn () => consent()->withdraw(['necessary'], 'preferences'))
        ->toThrow(InvalidConsentInput::class, 'necessary_not_refusable');
    expect(ledgerRows())->toHaveCount(1);
});

it('refuses unknown categories and sources', function (): void {
    makeRequest('POST');

    expect(fn () => consent()->record(['adtech'], 'banner'))->toThrow(InvalidConsentInput::class, 'unknown_category')
        ->and(fn () => consent()->record([], 'carrier-pigeon'))->toThrow(InvalidConsentInput::class, 'invalid_source')
        ->and(fn () => consent()->withdraw(['adtech'], 'preferences'))->toThrow(InvalidConsentInput::class, 'unknown_category')
        ->and(ledgerRows())->toBe([]);
});

it('fails closed for a category the host never configured', function (): void {
    makeRequest('POST');
    consent()->record(['analytics', 'marketing'], 'banner');

    expect(consent()->allows('adtech'))->toBeFalse();
});

it('refuses to update or delete a ledger row through the model', function (): void {
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    $row = ConsentRecord::query()->firstOrFail();

    expect(fn () => $row->forceFill(['source' => 'api'])->save())->toThrow(LedgerIsAppendOnly::class)
        ->and(fn () => $row->delete())->toThrow(LedgerIsAppendOnly::class)
        ->and(ledgerRows()[0]->source)->toBe('banner');
});

it('asks again when the policy version changes', function (): void {
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    $token = consent()->pendingGuestCookie(app('request'))->getValue();

    config()->set('fancy-compliance.policy_version', '2');
    makeRequest('GET', '/', [GUEST_COOKIE => $token]);
    $state = consent()->state();

    expect($state->needsConsent())->toBeTrue()
        ->and($state->current)->toBeFalse()
        ->and($state->allows('analytics'))->toBeFalse()
        ->and($state->allows('necessary'))->toBeTrue();

    consent()->record(['analytics'], 'banner');
    expect(consent()->state()->needsConsent())->toBeFalse()
        ->and(ledgerRows()[1]->policy_version)->toBe('2');
});

it('never stores the raw IP address or the user agent', function (): void {
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    consent()->withdraw(null, 'preferences');

    $columns = Schema::getColumnListing('fancy_compliance_consents');
    foreach ($columns as $column) {
        expect(strtolower($column))->not->toContain('ip')
            ->and(strtolower($column))->not->toContain('agent');
    }
    foreach (ledgerRows() as $row) {
        foreach ((array) $row as $value) {
            expect((string) $value)->not->toContain('203.0.113.7')
                ->and((string) $value)->not->toContain('fingerprint-canary')
                ->and((string) $value)->not->toContain(hash('sha256', '203.0.113.7'));
        }
    }
});

it('records a signed-in person against their user id and sets no guest cookie', function (): void {
    $user = makeUser();
    $request = makeRequest('POST', '/', [], $user);

    consent()->record(['analytics'], 'banner');

    expect(ledgerRows()[0]->subject_type)->toBe('user')
        ->and(ledgerRows()[0]->subject_id)->toBe((string) $user->id)
        ->and(consent()->pendingGuestCookie($request))->toBeNull();
});

it('links a guest decision to the user on login, by appending', function (): void {
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    $token = consent()->pendingGuestCookie(app('request'))->getValue();
    $guestRow = ledgerRows()[0];

    $user = makeUser();
    Carbon::setTestNow('2026-11-05 12:05:00');
    makeRequest('POST', '/login', [GUEST_COOKIE => $token], $user);
    event(new Login('web', $user, false));

    $rows = ledgerRows();
    expect($rows)->toHaveCount(2)
        ->and((array) $rows[0])->toBe((array) $guestRow)
        ->and($rows[1]->subject_type)->toBe('user')
        ->and($rows[1]->subject_id)->toBe((string) $user->id)
        ->and($rows[1]->action)->toBe('link')
        ->and($rows[1]->source)->toBe('login')
        ->and($rows[1]->linked_from)->toBe($guestRow->subject_id)
        ->and(json_decode($rows[1]->granted, true))->toBe(['necessary', 'analytics'])
        ->and(consent()->allows('analytics'))->toBeTrue();
});

it('does not let an older guest decision overwrite a newer one the user made', function (): void {
    makeRequest('POST');
    consent()->record(['analytics', 'marketing'], 'banner');
    $token = consent()->pendingGuestCookie(app('request'))->getValue();

    $user = makeUser();
    Carbon::setTestNow('2026-11-05 12:10:00');
    makeRequest('POST', '/', [], $user);
    consent()->record([], 'preferences');

    Carbon::setTestNow('2026-11-05 12:20:00');
    makeRequest('POST', '/login', [GUEST_COOKIE => $token], $user);
    event(new Login('web', $user, false));

    expect(ledgerRows())->toHaveCount(2)
        ->and(consent()->allows('analytics'))->toBeFalse();
});

it('ignores a guest cookie that is not a token this package could have issued', function (string $value): void {
    makeRequest('GET', '/', [GUEST_COOKIE => $value]);

    expect(consent()->subject(app('request')))->toBeNull()
        ->and(consent()->state()->needsConsent())->toBeTrue();
})->with([
    'short' => 'abc',
    'long' => str_repeat('a', 4096),
    'bad characters' => str_repeat('a', 42).'/',
]);
