<?php

declare(strict_types=1);

namespace FancyCompliance\Tests\Support;

use FancyCompliance\ComplianceServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;

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
    /** @param array<string, mixed> $config dotted keys merged over the defaults */
    public static function boot(array $config = [], string $env = 'testing'): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-compliance-tests';
        foreach (['/config', '/views', '/compiled', '/lang'] as $dir) {
            if (! is_dir($base.$dir)) {
                mkdir($base.$dir, 0777, true);
            }
        }
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
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
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
        $app->register(ComplianceServiceProvider::class);

        self::migrate(dirname(__DIR__, 2).'/database/migrations');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $app->boot();

        return $app;
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
