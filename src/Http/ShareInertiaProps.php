<?php

declare(strict_types=1);

namespace FancyCompliance\Http;

use Closure;
use FancyCompliance\ConsentManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the consent props with Inertia on every request.
 *
 * Sharing once in a service provider's boot() is not enough: Inertia's
 * ResponseFactory is a singleton, and long-lived servers (Octane, an
 * in-process browser-test server) call its flushShared() between requests.
 * From the second request on, a boot-time share is gone and the UI receives
 * no prop. The provider pushes this onto the `web` group; a host rendering
 * Inertia pages outside `web` can add it to that group itself.
 */
final class ShareInertiaProps
{
    public function __construct(private readonly Repository $config, private readonly ConsentManager $consent) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('fancy-compliance.inertia.share', true) && class_exists(Inertia::class)) {
            $consent = $this->consent;
            Inertia::share(
                (string) $this->config->get('fancy-compliance.inertia.key', 'fancyCompliance'),
                fn (): array => $consent->props(),
            );
        }

        return $next($request);
    }
}
