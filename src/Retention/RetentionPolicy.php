<?php

declare(strict_types=1);

namespace FancyCompliance\Retention;

/**
 * One retention policy in the registry, run by `fancy-compliance:retention`.
 *
 * Most packages need only `Retention::policy()` (a {@see CutoffPolicy}: a
 * number of days and a prune closure). Implement this directly when the
 * package keeps several windows of its own and already knows how to count
 * them; `run(true)` MUST delete nothing.
 */
interface RetentionPolicy
{
    /** Lowercase, dotted, unique in the registry: `<package>.<what>`. */
    public function key(): string;

    public function run(bool $dryRun): RetentionOutcome;
}
