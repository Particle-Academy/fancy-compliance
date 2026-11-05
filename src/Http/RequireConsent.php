<?php

declare(strict_types=1);

namespace FancyCompliance\Http;

use Closure;
use FancyCompliance\Contracts\ConsentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `consent:analytics` answers 403 `{"error":"consent_required"}` until the
 * person has consented to the category.
 *
 * `consent:analytics,drop` answers 204 instead: for collection endpoints
 * (beacons, batches), where a client should not see an error for data the
 * server was never allowed to keep. Nothing reaches the route either way.
 *
 * The guest cookie must be readable on the gated route. In the `web` group
 * EncryptCookies decrypts it; on a route outside that group, add the cookie
 * name to EncryptCookies' exceptions, or every guest reads as undecided and is
 * refused (which fails closed, but collects nothing).
 */
final class RequireConsent
{
    public function __construct(private readonly ConsentGate $gate) {}

    public function handle(Request $request, Closure $next, string $category, ?string $mode = null): Response
    {
        if ($this->gate->allows($category, $request)) {
            return $next($request);
        }
        if ($mode === 'drop') {
            return new Response('', 204, ['X-Fancy-Compliance' => 'consent-required']);
        }

        return new JsonResponse(['error' => 'consent_required', 'category' => $category], 403);
    }
}
