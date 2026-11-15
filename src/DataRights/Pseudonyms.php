<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

/**
 * Keyed hashes that stand in for a subject, so a table can find a person's
 * rows without naming them.
 *
 * - **Erasure**: HMAC-SHA256 of the subject with the dedicated erasure key
 *   (`FANCY_COMPLIANCE_ERASURE_KEY`). Stable for as long as that key is, which
 *   is what lets a re-keyed consent row and the audit trail be matched to a
 *   later request. Rotating the key orphans every earlier pseudonym.
 * - **Exports**: HMAC-SHA256 under a subkey of the app key. Export records are
 *   short-lived, so they follow the app key and need no key of their own.
 */
final class Pseudonyms
{
    private const SEPARATOR = "\x1F";

    public static function erasure(DataSubject $subject, string $key): string
    {
        return hash_hmac('sha256', $subject->type.self::SEPARATOR.$subject->id, $key);
    }

    public static function exportSubject(DataSubject $subject, mixed $appKey): string
    {
        if (! is_string($appKey) || $appKey === '') {
            throw new InvalidDataRightsConfiguration('Exports need the application key (app.key).');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }
        $subkey = hash_hmac('sha256', 'fancy-compliance.exports', $appKey, true);

        return hash_hmac('sha256', $subject->type.self::SEPARATOR.$subject->id, $subkey);
    }

    /** 32 random bytes, base64url without padding: 43 URL-safe characters. */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** A record id: 16 random bytes as 32 lowercase hex characters. */
    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }
}
