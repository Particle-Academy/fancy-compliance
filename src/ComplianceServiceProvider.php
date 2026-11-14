<?php

declare(strict_types=1);

namespace FancyCompliance;

use FancyCompliance\Console\PruneCommand;
use FancyCompliance\Console\RetentionCommand;
use FancyCompliance\Contracts\ConsentGate;
use FancyCompliance\DataRights\DataRightsManager;
use FancyCompliance\Http\ConsentController;
use FancyCompliance\Http\HistoryController;
use FancyCompliance\Http\RequireConsent;
use FancyCompliance\Http\ShareInertiaProps;
use FancyCompliance\Retention\LedgerRetention;
use FancyCompliance\Retention\RetentionRegistry;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Inertia\Inertia;

final class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fancy-compliance.php', 'fancy-compliance');
        $this->app->singleton(ConsentManager::class, fn ($app) => new ConsentManager($app));
        $this->app->alias(ConsentManager::class, ConsentGate::class);
        $this->app->singleton(DataRightsManager::class, fn ($app) => new DataRightsManager($app->make(ConsentManager::class)));
        // The ledger's own policy is registered first, so it always exists and runs first.
        $this->app->singleton(RetentionRegistry::class, function ($app): RetentionRegistry {
            $registry = new RetentionRegistry;
            LedgerRetention::register($registry, $app);

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'fancy-compliance');
        $this->publishes([__DIR__.'/../config/fancy-compliance.php' => $this->app->configPath('fancy-compliance.php')], 'fancy-compliance-config');
        $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/fancy-compliance')], 'fancy-compliance-lang');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'fancy-compliance-migrations');

        $this->commands([PruneCommand::class, RetentionCommand::class]);

        $config = $this->app->make('config');
        $router = $this->app->make('router');
        $router->aliasMiddleware('consent', RequireConsent::class);

        if ($config->get('fancy-compliance.routes.enabled', true)) {
            $router->group([
                'prefix' => (string) $config->get('fancy-compliance.routes.prefix', 'fancy-compliance'),
                'middleware' => (array) $config->get('fancy-compliance.routes.middleware', ['web']),
            ], function ($router) use ($config): void {
                $router->get('consent', [ConsentController::class, 'show'])->name('fancy-compliance.show');
                $router->post('consent', [ConsentController::class, 'record'])->name('fancy-compliance.record');
                $router->post('consent/withdraw', [ConsentController::class, 'withdraw'])->name('fancy-compliance.withdraw');
                if ($config->get('fancy-compliance.data_rights.history.enabled', true)) {
                    // Answers 404 until the host registers a subject resolver: fail closed.
                    $router->get('history', HistoryController::class)->name('fancy-compliance.history');
                }
            });
        }

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->if('consent', fn (string $category): bool => $this->app->make(ConsentManager::class)->allows($category));
            $blade->directive('fancyComplianceProps', fn (): string => '<?php echo app('.ConsentManager::class.'::class)->propsScript(); ?>');
        });

        // Inertia is optional: share only when the host has it. The boot-time share
        // covers a classic one-request-per-process server on any route; the `web`
        // middleware shares again per request, because Octane and other long-lived
        // servers flush Inertia's shared props between requests.
        if ($config->get('fancy-compliance.inertia.share', true) && class_exists(Inertia::class)) {
            $this->appendToWebGroup($router);
            Inertia::share(
                (string) $config->get('fancy-compliance.inertia.key', 'fancyCompliance'),
                fn (): array => $this->app->make(ConsentManager::class)->props(),
            );
        }

        $this->app->make('events')->listen(Login::class, function (Login $event): void {
            $this->app->make(ConsentManager::class)->linkGuestToUser($this->app->make('request'), $event->user);
        });
    }

    /**
     * Adds ShareInertiaProps to `web` through the HTTP kernel when it owns the
     * groups (Laravel 11+). The kernel's syncMiddlewareToRouter() rewrites the
     * router's groups from its own, so a push onto the router alone is dropped
     * the next time it syncs (Pest's in-process browser server does). The
     * kernel skips a middleware the group already has, so a host that declares
     * it too gets it once. Without such a kernel, the router is the only list.
     */
    private function appendToWebGroup(Router $router): void
    {
        if ($this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'appendMiddlewareToGroup') && method_exists($kernel, 'getMiddlewareGroups')
                && array_key_exists('web', $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup('web', ShareInertiaProps::class);

                return;
            }
        }

        $router->pushMiddlewareToGroup('web', ShareInertiaProps::class);
    }
}
