<?php

declare(strict_types=1);

namespace FancyCompliance;

use DateTimeInterface;

/**
 * A subject's consent right now: the latest ledger row, read against the
 * current policy version.
 *
 * A row recorded under an older policy version is not current. It still says
 * what the person agreed to back then (it is evidence and is shown as such),
 * but it grants nothing beyond the required categories until they decide again.
 */
final readonly class ConsentState
{
    /**
     * @param  list<string>  $granted
     * @param  list<string>  $refused
     * @param  list<string>  $required  categories that are always allowed
     */
    public function __construct(
        public array $granted,
        public array $refused,
        public array $required,
        public ?string $policyVersion,
        public ?DateTimeInterface $decidedAt,
        public ?string $source,
        public bool $current,
    ) {}

    /** @param list<string> $required */
    public static function undecided(array $required): self
    {
        return new self($required, [], $required, null, null, null, false);
    }

    public function decided(): bool
    {
        return $this->decidedAt !== null;
    }

    /** True when the banner should show: no decision yet, or one made under an older policy. */
    public function needsConsent(): bool
    {
        return ! $this->decided() || ! $this->current;
    }

    public function allows(string $category): bool
    {
        if (in_array($category, $this->required, true)) {
            return true;
        }

        return $this->current && in_array($category, $this->granted, true);
    }

    /** @return array{granted: list<string>, refused: list<string>, policyVersion: string|null, decidedAt: string|null, source: string|null, current: bool}|null */
    public function toArray(): ?array
    {
        if (! $this->decided()) {
            return null;
        }

        return [
            'granted' => $this->granted,
            'refused' => $this->refused,
            'policyVersion' => $this->policyVersion,
            'decidedAt' => $this->decidedAt?->format('Y-m-d\TH:i:s\Z'),
            'source' => $this->source,
            'current' => $this->current,
        ];
    }
}
