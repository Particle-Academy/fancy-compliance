<?php

declare(strict_types=1);

namespace FancyCompliance\Facades;

use FancyCompliance\ConsentManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool allows(string $category, ?\Illuminate\Http\Request $request = null)
 * @method static \FancyCompliance\ConsentState state(?\Illuminate\Http\Request $request = null)
 * @method static \FancyCompliance\ConsentState record(array $granted, string $source = 'api', ?\Illuminate\Http\Request $request = null)
 * @method static \FancyCompliance\ConsentState withdraw(?array $categories = null, string $source = 'preferences', ?\Illuminate\Http\Request $request = null)
 * @method static \FancyCompliance\Categories categories()
 * @method static string policyVersion()
 * @method static array props(?\Illuminate\Http\Request $request = null)
 * @method static string propsScript(?\Illuminate\Http\Request $request = null)
 *
 * @see ConsentManager
 */
final class Consent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConsentManager::class;
    }
}
