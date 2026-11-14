<?php

declare(strict_types=1);

namespace FancyCompliance\Facades;

use FancyCompliance\DataRights\DataRightsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void subjects(callable $resolver)
 * @method static void authorize(callable $authorizer)
 * @method static void resolveFor(callable $resolver)
 * @method static bool configured()
 * @method static \FancyCompliance\DataRights\DataSubject|null subjectFor(\Illuminate\Http\Request $request)
 * @method static bool allows(\FancyCompliance\DataRights\DataSubject $actor, \FancyCompliance\DataRights\DataSubject $target, string $right)
 * @method static \FancyCompliance\DataRights\SubjectKeys keysFor(\FancyCompliance\DataRights\DataSubject $subject)
 * @method static \FancyCompliance\ConsentHistoryPage consentHistory(\FancyCompliance\DataRights\DataSubject $target, int $limit = 50, ?int $before = null)
 *
 * @see DataRightsManager
 */
final class DataRights extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DataRightsManager::class;
    }
}
