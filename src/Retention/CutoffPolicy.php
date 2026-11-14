<?php

declare(strict_types=1);

namespace FancyCompliance\Retention;

use Carbon\CarbonImmutable;
use Closure;

/**
 * A policy with one window: rows older than `days` are pruned.
 *
 * `days` is read when the policy RUNS (a closure is called then), so a config
 * change takes effect without re-registering. Null means the policy is off and
 * keeps everything. Anything other than a whole number of days, 1 or more (a
 * digit string from env() counts), is a failure, never "keep everything" and
 * never "delete everything".
 *
 * A dry run calls `count`, never `prune`. Without a `count` closure a dry run
 * reports the policy as uncountable and touches nothing.
 */
final class CutoffPolicy implements RetentionPolicy
{
    /**
     * @param  int|string|Closure|null  $days  or a closure returning one
     * @param  Closure(CarbonImmutable): int  $prune  deletes rows older than the cutoff, returns how many
     * @param  (Closure(CarbonImmutable): int)|null  $count  counts them, deleting nothing
     */
    public function __construct(
        private readonly string $key,
        private readonly mixed $days,
        private readonly Closure $prune,
        private readonly ?Closure $count = null,
        private readonly string $label = 'rows',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function run(bool $dryRun): RetentionOutcome
    {
        $days = $this->days instanceof Closure ? ($this->days)() : $this->days;
        if ($days === null) {
            return RetentionOutcome::off('retention is null: everything is kept');
        }
        if (is_string($days) && ctype_digit($days)) {
            $days = (int) $days;
        }
        if (! is_int($days) || $days < 1) {
            throw new InvalidRetentionPolicy("Retention for [{$this->key}] must be a whole number of days, 1 or more, or null.");
        }

        $before = CarbonImmutable::now()->subDays($days);
        if ($dryRun) {
            return $this->count === null ? RetentionOutcome::uncountable() : RetentionOutcome::counted([$this->label => (int) ($this->count)($before)]);
        }

        return RetentionOutcome::counted([$this->label => (int) ($this->prune)($before)]);
    }
}
