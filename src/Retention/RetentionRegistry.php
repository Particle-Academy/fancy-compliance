<?php

declare(strict_types=1);

namespace FancyCompliance\Retention;

use Closure;

/**
 * Every retention policy in the app, behind the `Retention` facade. One
 * command, `fancy-compliance:retention`, runs them all.
 *
 * A package registers its policy in its own service provider, only when
 * fancy-compliance is installed (`class_exists(RetentionRegistry::class)`).
 * Its own prune command, if it has one, keeps working.
 */
final class RetentionRegistry
{
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /** @var array<string, RetentionPolicy> */
    private array $policies = [];

    /**
     * A one-window policy. See {@see CutoffPolicy} for how `days` is read.
     *
     * @param  int|string|Closure|null  $days  anything else fails when the policy runs, not here,
     *                                         so one bad config value cannot stop the app booting
     * @param  Closure(\Carbon\CarbonImmutable): int  $prune
     * @param  (Closure(\Carbon\CarbonImmutable): int)|null  $count  enables --dry-run
     */
    public function policy(string $key, mixed $days,Closure $prune, ?Closure $count = null, string $label = 'rows'): RetentionPolicy
    {
        $policy = new CutoffPolicy($key, $days, $prune, $count, $label);
        $this->register($policy);

        return $policy;
    }

    public function register(RetentionPolicy $policy): void
    {
        $key = $policy->key();
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidRetentionPolicy("A retention policy key is 1 to 64 lowercase letters, digits, dots, dashes or underscores; got [{$key}].");
        }
        if (isset($this->policies[$key])) {
            throw new InvalidRetentionPolicy("A retention policy named [{$key}] is already registered.");
        }
        $this->policies[$key] = $policy;
    }

    public function has(string $key): bool
    {
        return isset($this->policies[$key]);
    }

    public function get(string $key): RetentionPolicy
    {
        return $this->policies[$key] ?? throw new InvalidRetentionPolicy("No retention policy named [{$key}].");
    }

    /** @return list<string> in registration order */
    public function keys(): array
    {
        return array_keys($this->policies);
    }
}
