<?php

declare(strict_types=1);

namespace Inertia;

/**
 * Stands in for inertiajs/inertia-laravel's `Inertia` facade, which is not a
 * dependency of this package. It records `share()` exactly as the real facade
 * receives it: `Inertia::share(string $key, mixed $value)`.
 *
 * What this does NOT prove: that the real ResponseFactory resolves the closure
 * on a real page visit. That is the showcase's job when it dogfoods the package.
 */
final class Inertia
{
    /** @var array<string, mixed> */
    public static array $shared = [];

    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = [...self::$shared, ...$key];

            return;
        }
        self::$shared[$key] = $value;
    }
}
