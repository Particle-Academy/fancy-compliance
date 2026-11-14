<?php

declare(strict_types=1);

namespace FancyCompliance\Facades;

use FancyCompliance\Retention\RetentionRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \FancyCompliance\Retention\RetentionPolicy policy(string $key, int|string|\Closure|null $days, \Closure $prune, ?\Closure $count = null, string $label = 'rows')
 * @method static void register(\FancyCompliance\Retention\RetentionPolicy $policy)
 * @method static bool has(string $key)
 * @method static \FancyCompliance\Retention\RetentionPolicy get(string $key)
 * @method static list<string> keys()
 *
 * @see RetentionRegistry
 */
final class Retention extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RetentionRegistry::class;
    }
}
