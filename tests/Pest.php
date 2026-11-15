<?php

declare(strict_types=1);

use FancyCompliance\ConsentManager;
use FancyCompliance\Console\PruneCommand;
use FancyCompliance\Tests\Support\TestApp;
use FancyCompliance\Tests\Support\User;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Component;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Cookie;

// Every test boots its own kernel; drop it afterwards so no container, facade,
// in-memory database or frozen clock leaks into the next test.
uses()->beforeEach(function (): void {
    Carbon::setTestNow('2026-11-05 12:00:00');
    Inertia\Inertia::$shared = [];
    Inertia\Inertia::$shareCalls = [];
})->afterEach(function (): void {
    Carbon::setTestNow();
    // Blade::render() caches its view names statically, against the previous kernel's view factory.
    Component::flushCache();
    Component::forgetFactory();
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

/** @param array<string, list<string>>|null $kernelGroups when given, a real HTTP kernel owns the middleware groups */
function complianceApp(array $config = [], string $env = 'testing', ?array $kernelGroups = null): Application
{
    return TestApp::boot(['fancy-compliance.categories' => defaultCategories(), ...$config], $env, $kernelGroups);
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
    $command = app(PruneCommand::class);
    $command->setLaravel(app());

    return $command->run(new ArrayInput([]), new BufferedOutput);
}

function makeUser(string $name = 'Ada'): User
{
    return User::query()->create(['name' => $name]);
}

/**
 * A kernel with data rights wired the way most hosts will: the signed-in user
 * is the subject. Re-authentication is off unless a test turns it back on,
 * because these tests are about what follows it; ReauthTest covers the gate.
 */
function rightsApp(array $config = []): Application
{
    $app = complianceApp(['fancy-compliance.data_rights.reauth.required' => false, ...$config]);
    FancyCompliance\Facades\DataRights::subjects(fn (Request $r): ?FancyCompliance\DataRights\DataSubject => $r->user() === null
        ? null
        : FancyCompliance\DataRights\DataSubject::of('user', $r->user()->getAuthIdentifier()));

    return $app;
}

/** Ledger rows for $user: a decision, then a withdrawal. */
function seedConsents(User $user): void
{
    makeRequest('POST', '/', [], $user);
    consent()->record(['analytics'], 'banner');
    makeRequest('POST', '/', [], $user);
    consent()->withdraw(['analytics'], 'preferences');
}

/** @return array{status: int, headers: Symfony\Component\HttpFoundation\ResponseHeaderBag, content: string} */
function rawHttp(string $method, string $uri, ?User $user = null): array
{
    $request = makeRequest($method, $uri, [], $user);
    $response = app('router')->dispatch($request);
    $content = $response instanceof Symfony\Component\HttpFoundation\StreamedResponse ? (function () use ($response): string {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    })() : (string) $response->getContent();

    return ['status' => $response->getStatusCode(), 'headers' => $response->headers, 'content' => $content];
}

/** Every file under the test disk, relative paths. @return list<string> */
function diskFiles(): array
{
    $root = FancyCompliance\Tests\Support\TestApp::storagePath();
    if (! is_dir($root)) {
        return [];
    }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
    sort($files);

    return $files;
}

/**
 * A registrant built from closures, as a host would register its own tables.
 *
 * @param  (Closure(FancyCompliance\DataRights\SubjectKeys, FancyCompliance\DataRights\ExportWriter): void)|null  $export
 * @param  (Closure(FancyCompliance\DataRights\SubjectKeys, FancyCompliance\DataRights\ErasurePlan): void)|null  $erase
 */
function registrant(string $key, ?Closure $export = null, ?Closure $erase = null, int $holds = 0): FancyCompliance\DataRights\DataRegistrant
{
    return new class($key, $export, $erase, $holds) implements FancyCompliance\DataRights\DataRegistrant
    {
        public function __construct(private string $name, private ?Closure $exporter, private ?Closure $eraser, private int $held) {}

        public function key(): string
        {
            return $this->name;
        }

        public function version(): string
        {
            return '9.9.9';
        }

        public function export(FancyCompliance\DataRights\SubjectKeys $keys, FancyCompliance\DataRights\ExportWriter $writer): void
        {
            if ($this->exporter !== null) {
                ($this->exporter)($keys, $writer);
            }
        }

        public function erase(FancyCompliance\DataRights\SubjectKeys $keys, FancyCompliance\DataRights\ErasurePlan $plan): void
        {
            if ($this->eraser !== null) {
                ($this->eraser)($keys, $plan);
            }
        }

        public function holds(FancyCompliance\DataRights\SubjectKeys $keys): int
        {
            return $this->held;
        }
    };
}
