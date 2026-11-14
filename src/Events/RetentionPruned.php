<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

/** A retention policy ran for real (never on a dry run). `counts` are rows deleted per class. */
final readonly class RetentionPruned
{
    /** @param array<string, int> $counts */
    public function __construct(public string $key, public array $counts) {}
}
