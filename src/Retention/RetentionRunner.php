<?php

declare(strict_types=1);

namespace FancyCompliance\Retention;

use FancyCompliance\Events\RetentionPruned;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Runs policies for a command and writes one line per policy. A failing policy
 * is reported and the rest still run; the result is false if any failed, so
 * the command exits non-zero. A scheduler that reads exit codes sees it.
 */
final class RetentionRunner
{
    public function __construct(private readonly RetentionRegistry $registry, private readonly Dispatcher $events) {}

    /** @param list<string> $keys */
    public function run(array $keys, bool $dryRun, Command $out): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            try {
                $outcome = $this->registry->get($key)->run($dryRun);
            } catch (Throwable $e) {
                $ok = false;
                $out->error("{$key}: FAILED: {$e->getMessage()}");

                continue;
            }
            $out->line("{$key}: ".$this->describe($outcome, $dryRun));
            if (! $dryRun && $outcome->status === RetentionOutcome::COUNTED) {
                $this->events->dispatch(new RetentionPruned($key, $outcome->counts));
            }
        }

        return $ok;
    }

    private function describe(RetentionOutcome $outcome, bool $dryRun): string
    {
        return match ($outcome->status) {
            RetentionOutcome::OFF => 'off ('.$outcome->reason.')',
            RetentionOutcome::UNCOUNTABLE => 'skipped: this policy cannot count without deleting, so a dry run leaves it alone',
            default => ($dryRun ? 'would delete ' : 'deleted ').$outcome->total().$this->breakdown($outcome->counts),
        };
    }

    /** @param array<string, int> $counts */
    private function breakdown(array $counts): string
    {
        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = "{$label} {$count}";
        }

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }
}
