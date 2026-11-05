<?php

declare(strict_types=1);

namespace FancyCompliance;

/**
 * Whose consent a ledger row records: a user (by auth identifier) or a guest.
 *
 * A guest is identified by a random token in a first-party cookie. The ledger
 * stores only the token's SHA-256, so a copy of the table cannot be replayed as
 * somebody's cookie. The token is random and carries nothing about the person;
 * it is not derived from the IP address, the user agent or anything else.
 */
final readonly class Subject
{
    public const USER = 'user';

    public const GUEST = 'guest';

    private function __construct(public string $type, public string $id) {}

    public static function user(string|int $identifier): self
    {
        return new self(self::USER, (string) $identifier);
    }

    public static function guest(string $token): self
    {
        return new self(self::GUEST, hash('sha256', $token));
    }
}
