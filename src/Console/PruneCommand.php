<?php

declare(strict_types=1);

namespace FancyCompliance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes ledger rows older than `fancy-compliance.retention.days`, keeping
 * every subject's latest row (their current state). Does nothing while the
 * retention is null, which is the default: a consent record is the evidence
 * that consent was given.
 */
final class PruneCommand extends Command
{
    protected $signature = 'fancy-compliance:prune';

    protected $description = 'Delete consent ledger rows past the retention window, keeping each subject\'s latest row';

    public function handle(): int
    {
        $days = $this->laravel['config']->get('fancy-compliance.retention.days');
        if ($days === null) {
            $this->info('Retention is off (fancy-compliance.retention.days is null): every consent record is kept.');

            return self::SUCCESS;
        }
        if (is_string($days) && ctype_digit($days)) {
            $days = (int) $days; // from env()
        }
        if (! is_int($days) || $days < 1) {
            $this->error('fancy-compliance.retention.days must be a whole number of days, 1 or more, or null.');

            return self::FAILURE;
        }

        $table = (string) $this->laravel['config']->get('fancy-compliance.table', 'fancy_compliance_consents');
        $db = $this->laravel['db']->connection();
        $latest = $db->table($table)->selectRaw('max(id) as id')->groupBy('subject_type', 'subject_id');
        $deleted = $db->table($table)
            ->where('created_at', '<', Carbon::now()->subDays($days))
            // Through a derived table, so MySQL accepts a subquery on the table being deleted from.
            ->whereNotIn('id', fn ($query) => $query->fromSub($latest, 'latest_per_subject')->select('id'))
            ->delete();

        $this->info("Pruned {$deleted} consent records older than {$days} days; each subject's latest record was kept.");

        return self::SUCCESS;
    }
}
