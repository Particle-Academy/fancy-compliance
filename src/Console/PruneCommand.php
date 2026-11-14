<?php

declare(strict_types=1);

namespace FancyCompliance\Console;

use FancyCompliance\Retention\LedgerRetention;
use FancyCompliance\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * The 0.1 name for the consent ledger's retention, kept as an alias: it runs
 * the `fancy-compliance.ledger` policy and nothing else, so a host that
 * scheduled it keeps exactly the behaviour it had. `fancy-compliance:retention`
 * runs every policy.
 */
final class PruneCommand extends Command
{
    protected $signature = 'fancy-compliance:prune {--dry-run : Count what would be deleted, and delete nothing}';

    protected $description = 'Delete consent ledger rows past the retention window, keeping each subject\'s latest row (alias of fancy-compliance:retention --only=fancy-compliance.ledger)';

    public function handle(RetentionRunner $runner): int
    {
        return $runner->run([LedgerRetention::KEY], (bool) $this->option('dry-run'), $this) ? self::SUCCESS : self::FAILURE;
    }
}
