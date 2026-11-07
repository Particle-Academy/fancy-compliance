<?php

declare(strict_types=1);

namespace FancyCompliance;

use FancyCompliance\Console\PruneCommand;
use FancyCompliance\Contracts\ConsentGate;
use FancyCompliance\Http\ConsentController;
use FancyCompliance\Http\RequireConsent;
use FancyCompliance\Http\ShareInertiaProps;
use Illuminate\Auth\Events\Login;
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'fancy-compliance');
        $this->publishes([__DIR__.'/../config/fancy-compliance.php' => $this->app->configPath('fancy-compliance.php')], 'fancy-compliance-config');
        $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/fancy-compliance')], 'fancy-compliance-lang');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'fancy-compliance-migrations');

        $this->commands([PruneCommand::class]);

        $config = $this->app->make('config');
        $router = $this->app->make('router');
        $router->aliasMiddleware('consent', RequireConsent::class);

        if ($config->get('fancy-compliance.routes.enabled', true)) {
            $router->group([
                'prefix' => (string) $config->get('fancy-compliance.routes.prefix', 'fancy-compliance'),
                'middleware' => (array) $config->get('fancy-compliance.routes.middleware', ['web']),
            ], function ($router): void {
                $router->get('consent', [ConsentController::class, 'show'])->name('fancy-compliance.show');
                $router->post('consent', [ConsentController::class, 'record'])->name('fancy-compliance.record');
                $router->post('consent/withdraw', [ConsentController::class, 'withdraw'])->name('fancy-compliance.withdraw');
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
            $router->pushMiddlewareToGroup('web', ShareInertiaProps::class);
            Inertia::share(
                (string) $config->get('fancy-compliance.inertia.key', 'fancyCompliance'),
                fn (): array => $this->app->make(ConsentManager::class)->props(),
            );
        }

        $this->app->make('events')->listen(Login::class, function (Login $event): void {
            $this->app->make(ConsentManager::class)->linkGuestToUser($this->app->make('request'), $event->user);
        });
    }
}
