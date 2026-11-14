<?php

declare(strict_types=1);

namespace FancyCompliance\Retention;

/**
 * What one policy did: nothing because it is off, a count per class of data
 * (deleted, or on a dry run, would be deleted), or nothing because it cannot
 * count without deleting.
 */
final readonly class RetentionOutcome
{
    public const OFF = 'off';

    public const COUNTED = 'counted';

    public const UNCOUNTABLE = 'uncountable';

    /** @param array<string, int> $counts */
    private function __construct(public string $status, public array $counts, public ?string $reason) {}

    public static function off(string $reason): self
    {
        return new self(self::OFF, [], $reason);
    }

    /** @param array<string, int> $counts rows per class of data */
    public static function counted(array $counts): self
    {
        return new self(self::COUNTED, array_map('intval', $counts), null);
    }

    public static function uncountable(): self
    {
        return new self(self::UNCOUNTABLE, [], null);
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
