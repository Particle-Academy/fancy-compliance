<?php

declare(strict_types=1);

namespace FancyCompliance\Tests\Support;

use FancyCompliance\ComplianceServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Monolog\Handler\NullHandler;

/**
 * A hand-built Laravel 13 kernel: the framework this package integrates with,
 * booted directly, with no orchestra/testbench (the estate's approach, as in
 * change-council and agent-flo).
 *
 * In-memory SQLite and the array session, so every test is hermetic. The `web`
 * middleware group starts EMPTY here; the CSRF test fills it with the real
 * StartSession + ValidateCsrfToken pair, because that is the group a host's
 * routes sit in and the package must not bypass it.
 */
final class TestApp
{
    /**
     * @param  array<string, mixed>  $config  dotted keys merged over the defaults
     * @param  array<string, list<string>>|null  $kernelGroups  when given, a real HTTP kernel
     *                                                          owns the middleware groups, as in a Laravel 11+ app
     */
    public static function boot(array $config = [], string $env = 'testing', ?array $kernelGroups = null): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-compliance-tests';
        foreach (['/config', '/views', '/compiled', '/lang'] as $dir) {
            if (! is_dir($base.$dir)) {
                mkdir($base.$dir, 0777, true);
            }
        }
        // A fresh private disk per boot, so no export file leaks between tests.
        $storage = self::storagePath();
        self::wipe($storage);
        mkdir($storage, 0777, true);
        $app = new Application($base);
        $app->useConfigPath($base.'/config');
        $app->useLangPath($base.'/lang');
        $app['env'] = $env;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $repository = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'env' => $env, 'debug' => false, 'locale' => 'en', 'fallback_locale' => 'en'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]],
            'session' => ['driver' => 'array', 'lifetime' => 120, 'expire_on_close' => false, 'encrypt' => false, 'cookie' => 'test_session', 'path' => '/', 'domain' => null, 'secure' => false, 'http_only' => true, 'same_site' => 'lax', 'lottery' => [0, 100]],
            'view' => ['paths' => [$base.'/views'], 'compiled' => $base.'/compiled'],
            'filesystems' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $storage, 'throw' => true]]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => NullHandler::class]]],
        ]);
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);

        foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, DatabaseServiceProvider::class,
            EncryptionServiceProvider::class, CookieServiceProvider::class, SessionServiceProvider::class,
            TranslationServiceProvider::class, ViewServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app['router']->middlewareGroup('web', []);
        if ($kernelGroups !== null) {
            // Resolved before the providers boot, as public/index.php does; it
            // syncs its own groups onto the router, replacing the router's.
            $app->singleton(HttpKernelContract::class, HttpKernel::class);
            $app->make(HttpKernelContract::class)->setMiddlewareGroups($kernelGroups);
        }
        $app->register(ComplianceServiceProvider::class);
        // The package config is merged now (shallowly, under the host's keys), so
        // nested test settings go in after it: an erasure key, then the test's own
        // overrides again, which may set that key back to null.
        $repository->set('fancy-compliance.data_rights.erasure.key', 'base64:'.base64_encode(str_repeat('e', 32)));
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }

        self::migrate(dirname(__DIR__, 2).'/database/migrations');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $app->boot();

        return $app;
    }

    public static function storagePath(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-compliance-tests'.DIRECTORY_SEPARATOR.'storage';
    }

    private static function wipe(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function migrate(string $directory): void
    {
        $files = glob($directory.'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
}
