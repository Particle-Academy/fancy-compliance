<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

/**
 * Every identifier the host says belongs to one subject, by name. A package
 * reads the key it stores data under and never derives one key from another
 * (no `user_id` to `member_id` guess).
 *
 * The consent ledger reads {@see self::CONSENT_USER}: the auth identifier its
 * `user` rows were recorded under. Without a host key resolver the keys are
 * `[type => id]` of the subject, so a `user` subject reads its own rows.
 */
final readonly class SubjectKeys
{
    /** The key the consent ledger's `user` rows are found under. */
    public const CONSENT_USER = 'user';

    /** @param array<string, mixed> $keys */
    private function __construct(private array $keys) {}

    /** @param array<string, mixed> $keys null values are dropped */
    public static function of(array $keys): self
    {
        return new self(array_filter($keys, static fn (mixed $value): bool => $value !== null));
    }

    public static function defaultFor(DataSubject $subject): self
    {
        return new self([$subject->type => $subject->id]);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->keys);
    }

    public function get(string $name): mixed
    {
        return $this->keys[$name] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->keys;
    }
}
