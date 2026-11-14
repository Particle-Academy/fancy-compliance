<?php

declare(strict_types=1);

namespace FancyCompliance\Console;

use FancyCompliance\Retention\RetentionRegistry;
use FancyCompliance\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * Runs every registered retention policy, or the ones named with --only.
 * Schedule it once (`Schedule::command('fancy-compliance:retention')->daily()`);
 * every policy decides its own window, and a policy whose days are null keeps
 * everything.
 */
final class RetentionCommand extends Command
{
    protected $signature = 'fancy-compliance:retention
        {--dry-run : Count what each policy would delete, and delete nothing}
        {--only=* : Run only these policy keys}';

    protected $description = 'Run every registered retention policy';

    public function handle(RetentionRegistry $registry, RetentionRunner $runner): int
    {
        $only = array_values(array_filter((array) $this->option('only'), 'is_string'));
        $unknown = array_values(array_filter($only, fn (string $key): bool => ! $registry->has($key)));
        if ($unknown !== []) {
            $this->error('Unknown retention policy: '.implode(', ', $unknown).'. Registered: '.implode(', ', $registry->keys()).'. Nothing was run.');

            return self::FAILURE;
        }
        $keys = $only === [] ? $registry->keys() : array_values(array_unique($only));
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->info('Dry run: nothing is deleted.');
        }

        return $runner->run($keys, $dryRun, $this) ? self::SUCCESS : self::FAILURE;
    }
}
