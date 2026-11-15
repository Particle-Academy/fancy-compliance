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
 * @method static void confirmRecent(callable $confirmer)
 * @method static bool confirmsRecent(\Illuminate\Http\Request $request)
 * @method static void blockErasure(callable $blocker)
 * @method static void onErasureRequested(callable $callback)
 * @method static void register(\FancyCompliance\DataRights\DataRegistrant $registrant)
 * @method static list<\FancyCompliance\DataRights\DataRegistrant> registrants()
 * @method static array<string, int> holdings(\FancyCompliance\DataRights\DataSubject $subject)
 * @method static array<string, mixed> export(\FancyCompliance\DataRights\DataSubject $target, string $format = 'json')
 * @method static array<string, mixed> requestErasure(\FancyCompliance\DataRights\DataSubject $target)
 * @method static array<string, mixed> cancelErasure(\FancyCompliance\DataRights\DataSubject $target, string $id)
 * @method static array<string, mixed>|null executeErasure(string $id)
 * @method static \FancyCompliance\DataRights\DataRightsConfig config()
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
