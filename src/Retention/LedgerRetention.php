<?php

declare(strict_types=1);

namespace FancyCompliance\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;

/**
 * The consent ledger's own policy, `fancy-compliance.ledger`: rows older than
 * `fancy-compliance.retention.days`, except every subject's latest row (their
 * current state). Off while the days are null, which is the default, because a
 * consent record is the evidence that consent was given.
 *
 * Deletes through the query builder, which bypasses the model's append-only
 * guard on purpose: retention is the one sanctioned delete.
 */
final class LedgerRetention
{
    public const KEY = 'fancy-compliance.ledger';

    public static function register(RetentionRegistry $registry, Container $app): void
    {
        $config = $app->make('config');
        $registry->policy(
            self::KEY,
            days: fn (): mixed => $config->get('fancy-compliance.retention.days'),
            prune: fn (CarbonImmutable $before): int => self::query($app, $before)->delete(),
            count: fn (CarbonImmutable $before): int => self::query($app, $before)->count(),
            label: 'consents',
        );
    }

    private static function query(Container $app, CarbonImmutable $before): Builder
    {
        $table = (string) $app->make('config')->get('fancy-compliance.table', 'fancy_compliance_consents');
        $db = $app->make('db')->connection();
        $latest = $db->table($table)->selectRaw('max(id) as id')->groupBy('subject_type', 'subject_id');

        return $db->table($table)
            ->where('created_at', '<', $before)
            // Through a derived table, so MySQL accepts a subquery on the table being deleted from.
            ->whereNotIn('id', fn ($query) => $query->fromSub($latest, 'latest_per_subject')->select('id'));
    }
}
