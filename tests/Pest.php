<?php

declare(strict_types=1);

use FancyCompliance\ConsentManager;
use FancyCompliance\Tests\Support\TestApp;
use FancyCompliance\Tests\Support\User;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Cookie;

// Every test boots its own kernel; drop it afterwards so no container, facade,
// in-memory database or frozen clock leaks into the next test.
uses()->beforeEach(function (): void {
    Carbon::setTestNow('2026-11-05 12:00:00');
    Inertia\Inertia::$shared = [];
})->afterEach(function (): void {
    Carbon::setTestNow();
    // Blade::render() caches its view names statically, against the previous kernel's view factory.
    Illuminate\View\Component::flushCache();
    Illuminate\View\Component::forgetFactory();
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__);

const GUEST_COOKIE = 'fancy_compliance_subject';

/** The three categories most tests use: the required one plus two optional. */
function defaultCategories(): array
{
    return [
        'necessary' => ['label' => 'fancy-compliance::categories.necessary.label', 'description' => 'fancy-compliance::categories.necessary.description', 'purposes' => ['fancy-compliance::categories.necessary.purposes.session']],
        'analytics' => ['label' => 'fancy-compliance::categories.analytics.label', 'description' => 'fancy-compliance::categories.analytics.description', 'purposes' => ['fancy-compliance::categories.analytics.purposes.usage']],
        'marketing' => ['label' => 'fancy-compliance::categories.marketing.label', 'description' => 'fancy-compliance::categories.marketing.description', 'purposes' => ['fancy-compliance::categories.marketing.purposes.ads']],
    ];
}

function complianceApp(array $config = [], string $env = 'testing'): Application
{
    return TestApp::boot(['fancy-compliance.categories' => defaultCategories(), ...$config], $env);
}

function consent(): ConsentManager
{
    return app(ConsentManager::class);
}

/** A request as the browser would send it, bound as the current request. */
function makeRequest(string $method = 'GET', string $uri = '/', array $cookies = [], ?User $user = null, array $server = [], string $content = ''): Request
{
    $request = Request::create($uri, $method, [], $cookies, [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'FancyTestAgent/1.0 (fingerprint-canary)',
        ...$server,
    ], $content);
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);

    return $request;
}

/**
 * One request through the real router.
 *
 * @return array{status: int, body: array, raw: string, cookies: array<string, Cookie>}
 */
function http(string $method, string $uri, ?array $body = null, array $cookies = [], ?User $user = null, array $server = []): array
{
    $content = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
    $request = makeRequest($method, $uri, $cookies, $user, $server, $content);
    $response = app('router')->dispatch($request);
    $set = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $set[$cookie->getName()] = $cookie;
    }

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getContent(), true) ?? [],
        'raw' => (string) $response->getContent(),
        'cookies' => $set,
    ];
}

/** @return list<object> every ledger row, oldest first */
function ledgerRows(): array
{
    return DB::table('fancy_compliance_consents')->orderBy('id')->get()->all();
}

/** Runs the prune command the way artisan would, and returns its exit code. */
function runPrune(): int
{
    $command = app(FancyCompliance\Console\PruneCommand::class);
    $command->setLaravel(app());

    return $command->run(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\BufferedOutput);
}

function makeUser(string $name = 'Ada'): User
{
    return User::query()->create(['name' => $name]);
}
