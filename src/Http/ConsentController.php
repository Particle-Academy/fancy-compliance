<?php

declare(strict_types=1);

namespace FancyCompliance\Http;

use Closure;
use FancyCompliance\Categories;
use FancyCompliance\ConsentManager;
use FancyCompliance\InvalidConsentInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET the current state, POST a decision, POST a withdrawal.
 *
 * Input is bounded before the manager sees it: a list of at most 32 strings of
 * at most 32 characters, and a source from a fixed set. Anything else is a 422
 * with a stable error code, and nothing is written. The routes sit in the
 * host's `web` group, so CSRF applies; rate limiting is the host's to add.
 */
final class ConsentController
{
    public function __construct(private readonly ConsentManager $consent) {}

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse($this->consent->props($request));
    }

    public function record(Request $request): JsonResponse
    {
        return $this->attempt($request, function () use ($request): void {
            $granted = $request->input('granted');
            if (! is_array($granted)) {
                throw new InvalidConsentInput('invalid_input', '`granted` must be a list of category keys.');
            }
            $this->consent->record($this->keys($granted), $this->source($request, 'api'), $request);
        });
    }

    public function withdraw(Request $request): JsonResponse
    {
        return $this->attempt($request, function () use ($request): void {
            $categories = $request->input('categories');
            if ($categories !== null && ! is_array($categories)) {
                throw new InvalidConsentInput('invalid_input', '`categories` must be a list of category keys, or absent.');
            }
            $this->consent->withdraw($categories === null ? null : $this->keys($categories), $this->source($request, 'preferences'), $request);
        });
    }

    private function attempt(Request $request, Closure $write): JsonResponse
    {
        try {
            $write();
        } catch (InvalidConsentInput $e) {
            return new JsonResponse(['error' => $e->errorCode], 422);
        }
        $response = new JsonResponse($this->consent->props($request));
        $cookie = $this->consent->pendingGuestCookie($request);
        if ($cookie !== null) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return list<string>
     */
    private function keys(array $keys): array
    {
        if (! array_is_list($keys) || count($keys) > Categories::MAX) {
            throw new InvalidConsentInput('invalid_input', 'At most '.Categories::MAX.' category keys, as a list.');
        }
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '' || strlen($key) > 32) {
                throw new InvalidConsentInput('invalid_input', 'Each category key is a string of 1 to 32 characters.');
            }
        }

        return $keys;
    }

    private function source(Request $request, string $default): string
    {
        $source = $request->input('source', $default);
        if (! is_string($source)) {
            throw new InvalidConsentInput('invalid_source', '`source` must be a string.');
        }

        return $source;
    }
}
