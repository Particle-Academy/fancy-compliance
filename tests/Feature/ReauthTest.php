<?php

declare(strict_types=1);

use FancyCompliance\Facades\DataRights;
use FancyCompliance\Tests\Support\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Requesting an export or an erasure needs a recent re-authentication: a
 * host setting, ON by default (owner decision 4). The default check reads
 * Laravel's own `password.confirm` timestamp; a host can supply any other
 * (a passkey step-up, for example).
 */

/** A POST with a session, so the default check has something to read. */
function postWithSession(string $uri, User $user, ?int $confirmedAt): int
{
    $request = makeRequest('POST', $uri, [], $user, [], '{}');
    $session = new Store('test', new ArraySessionHandler(120));
    if ($confirmedAt !== null) {
        $session->put('auth.password_confirmed_at', $confirmedAt);
    }
    $request->setLaravelSession($session);

    return app('router')->dispatch($request)->getStatusCode();
}

function strictApp(array $config = []): void
{
    rightsApp(['fancy-compliance.data_rights.reauth.required' => true, ...$config]);
}

it('is on by default', function (): void {
    complianceApp();

    expect(config('fancy-compliance.data_rights.reauth.required'))->toBeTrue()
        ->and(config('fancy-compliance.data_rights.reauth.window_minutes'))->toBe(10);
});

it('refuses an export or an erasure without a recent re-authentication, and writes nothing', function (string $uri): void {
    strictApp();
    $ada = makeUser();

    $response = http('POST', $uri, [], [], $ada);

    expect($response['status'])->toBe(423)
        ->and($response['body'])->toBe(['error' => 'reauth_required'])
        ->and(DB::table('fancy_compliance_exports')->count())->toBe(0)
        ->and(DB::table('fancy_compliance_erasure_requests')->count())->toBe(0);
})->with(['export' => ['/fancy-compliance/exports'], 'erasure' => ['/fancy-compliance/erasure']]);

it('accepts Laravel\'s password confirmation within the window, and not after it', function (): void {
    strictApp();
    $ada = makeUser();
    $now = Carbon::now()->getTimestamp();

    expect(postWithSession('/fancy-compliance/exports', $ada, null))->toBe(423)
        ->and(postWithSession('/fancy-compliance/exports', $ada, $now - 11 * 60))->toBe(423)
        ->and(postWithSession('/fancy-compliance/exports', $ada, $now - 9 * 60))->toBe(202);
});

it('uses the configured window', function (): void {
    strictApp(['fancy-compliance.data_rights.reauth.window_minutes' => 30]);
    $ada = makeUser();

    expect(postWithSession('/fancy-compliance/erasure', $ada, Carbon::now()->getTimestamp() - 20 * 60))->toBe(202);
});

it('uses the host\'s own check when it supplies one', function (): void {
    strictApp();
    $asked = [];
    DataRights::confirmRecent(function (Request $request) use (&$asked): bool {
        $asked[] = $request->path();

        return $request->header('X-Step-Up') === 'passkey-ok';
    });
    $ada = makeUser();

    expect(http('POST', '/fancy-compliance/exports', [], [], $ada)['status'])->toBe(423)
        ->and(http('POST', '/fancy-compliance/exports', [], [], $ada, ['HTTP_X_STEP_UP' => 'passkey-ok'])['status'])->toBe(202)
        ->and($asked)->toBe(['fancy-compliance/exports', 'fancy-compliance/exports']);
});

it('does not ask for it to read status, download, or cancel', function (): void {
    rightsApp();
    $ada = makeUser();
    http('POST', '/fancy-compliance/exports', [], [], $ada);
    $erasure = http('POST', '/fancy-compliance/erasure', [], [], $ada)['body']['erasure'];
    config(['fancy-compliance.data_rights.reauth.required' => true]);

    $list = http('GET', '/fancy-compliance/exports', null, [], $ada);
    // Reading the list issued a fresh token; the one from the POST is spent.
    expect($list['status'])->toBe(200)
        ->and(rawHttp('GET', $list['body']['exports'][0]['download']['url'], $ada)['status'])->toBe(200)
        ->and(http('POST', '/fancy-compliance/erasure/'.$erasure['id'].'/cancel', [], [], $ada)['status'])->toBe(200);
});

it('authenticates before it asks for re-authentication: no subject is 401, not 423', function (): void {
    strictApp();

    expect(http('POST', '/fancy-compliance/exports', [])['status'])->toBe(401);
});
