<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

it('serves the current consent and the categories as JSON', function (): void {
    complianceApp();

    $response = http('GET', '/fancy-compliance/consent');

    expect($response['status'])->toBe(200)
        ->and($response['body']['policyVersion'])->toBe('1')
        ->and($response['body']['needsConsent'])->toBeTrue()
        ->and($response['body']['consent'])->toBeNull()
        ->and(array_column($response['body']['categories'], 'key'))->toBe(['necessary', 'analytics', 'marketing'])
        ->and($response['body']['categories'][0]['required'])->toBeTrue()
        ->and($response['body']['routes'])->toBe([
            'show' => '/fancy-compliance/consent',
            'record' => '/fancy-compliance/consent',
            'withdraw' => '/fancy-compliance/consent/withdraw',
        ]);
});

it('records a decision, answers with the new state and sets the guest cookie', function (): void {
    complianceApp();

    $response = http('POST', '/fancy-compliance/consent', ['granted' => ['analytics'], 'source' => 'banner']);

    expect($response['status'])->toBe(200)
        ->and($response['body']['needsConsent'])->toBeFalse()
        ->and($response['body']['consent']['granted'])->toBe(['necessary', 'analytics'])
        ->and($response['body']['consent']['refused'])->toBe(['marketing'])
        ->and($response['body']['consent']['source'])->toBe('banner')
        ->and($response['body']['consent']['decidedAt'])->toBe('2026-11-05T12:00:00Z')
        ->and($response['cookies'])->toHaveKey(GUEST_COOKIE)
        ->and($response['cookies'][GUEST_COOKIE]->isHttpOnly())->toBeTrue()
        ->and($response['cookies'][GUEST_COOKIE]->getSameSite())->toBe('lax');

    $token = $response['cookies'][GUEST_COOKIE]->getValue();
    $again = http('GET', '/fancy-compliance/consent', null, [GUEST_COOKIE => $token]);
    expect($again['body']['consent']['granted'])->toBe(['necessary', 'analytics']);
});

it('withdraws over HTTP by appending', function (): void {
    complianceApp();
    $token = http('POST', '/fancy-compliance/consent', ['granted' => ['analytics', 'marketing'], 'source' => 'banner'])['cookies'][GUEST_COOKIE]->getValue();

    $response = http('POST', '/fancy-compliance/consent/withdraw', ['categories' => ['marketing'], 'source' => 'preferences'], [GUEST_COOKIE => $token]);

    expect($response['status'])->toBe(200)
        ->and($response['body']['consent']['granted'])->toBe(['necessary', 'analytics'])
        ->and(ledgerRows())->toHaveCount(2);
});

it('refuses out-of-bounds input with 422 and writes nothing', function (array $body, string $code): void {
    complianceApp();

    $response = http('POST', '/fancy-compliance/consent', $body);

    expect($response['status'])->toBe(422)
        ->and($response['body']['error'])->toBe($code)
        ->and(ledgerRows())->toBe([]);
})->with([
    'unknown category' => [['granted' => ['adtech']], 'unknown_category'],
    'granted not a list' => [['granted' => 'analytics'], 'invalid_input'],
    'granted an object' => [['granted' => ['a' => 'analytics']], 'invalid_input'],
    'non-string entry' => [['granted' => [['analytics']]], 'invalid_input'],
    'too many entries' => [['granted' => array_fill(0, 33, 'analytics')], 'invalid_input'],
    'over-long entry' => [['granted' => [str_repeat('a', 33)]], 'invalid_input'],
    'unknown source' => [['granted' => [], 'source' => 'scraper'], 'invalid_source'],
    'missing granted' => [['source' => 'banner'], 'invalid_input'],
]);

it('refuses to withdraw the necessary category over HTTP', function (): void {
    complianceApp();
    $token = http('POST', '/fancy-compliance/consent', ['granted' => []])['cookies'][GUEST_COOKIE]->getValue();

    $response = http('POST', '/fancy-compliance/consent/withdraw', ['categories' => ['necessary']], [GUEST_COOKIE => $token]);

    expect($response['status'])->toBe(422)
        ->and($response['body']['error'])->toBe('necessary_not_refusable')
        ->and(ledgerRows())->toHaveCount(1);
});

it('sits behind the host web group, so CSRF protection applies', function (): void {
    complianceApp([], 'production');
    app('router')->middlewareGroup('web', [EncryptCookies::class, StartSession::class, ValidateCsrfToken::class]);

    // The router alone has no exception handler; the host's handler renders this as 419.
    expect(fn () => http('POST', '/fancy-compliance/consent', ['granted' => ['analytics']]))
        ->toThrow(TokenMismatchException::class);
    expect(ledgerRows())->toBe([]);
});

it('accepts a POST that carries the session CSRF token', function (): void {
    complianceApp([], 'production');
    app('router')->middlewareGroup('web', [StartSession::class, ValidateCsrfToken::class]);
    Route::middleware('web')->get('/token', fn () => response()->json(['token' => csrf_token()]));
    app('router')->getRoutes()->refreshNameLookups();

    $first = http('GET', '/token');
    $session = $first['cookies']['test_session']->getValue();

    $response = http('POST', '/fancy-compliance/consent', ['granted' => ['analytics']], ['test_session' => $session], null, ['HTTP_X_CSRF_TOKEN' => $first['body']['token']]);

    expect($response['status'])->toBe(200)
        ->and(ledgerRows())->toHaveCount(1);
});

it('lets the host move the routes, add middleware, or turn them off', function (): void {
    complianceApp(['fancy-compliance.routes.prefix' => 'privacy']);
    expect(http('GET', '/privacy/consent')['status'])->toBe(200);

    complianceApp(['fancy-compliance.routes.enabled' => false]);
    expect(app('router')->getRoutes()->getByName('fancy-compliance.record'))->toBeNull();
});

it('gates a route on consent with the consent middleware', function (): void {
    complianceApp();
    Route::middleware('consent:analytics')->post('/collect', fn () => response()->json(['stored' => true]));
    Route::middleware('consent:analytics,drop')->post('/beacon', fn () => response()->json(['stored' => true]));
    app('router')->getRoutes()->refreshNameLookups();

    expect(http('POST', '/collect')['status'])->toBe(403)
        ->and(http('POST', '/collect')['body']['error'])->toBe('consent_required')
        ->and(http('POST', '/beacon')['status'])->toBe(204);

    $token = http('POST', '/fancy-compliance/consent', ['granted' => ['analytics']])['cookies'][GUEST_COOKIE]->getValue();

    expect(http('POST', '/collect', null, [GUEST_COOKIE => $token])['status'])->toBe(200)
        ->and(http('POST', '/beacon', null, [GUEST_COOKIE => $token])['status'])->toBe(200);
});
