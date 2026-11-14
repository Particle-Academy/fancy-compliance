<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

/**
 * A person whose data rights are exercised, as the HOST names them: a type and
 * an identifier. Never assumed to be a `users` row, an integer, or an
 * Eloquent model. The host's subject resolver says who is asking; its key
 * resolver ({@see SubjectKeys}) says which identifiers each package should
 * look under.
 */
final readonly class DataSubject
{
    /** Lowercase, starts with a letter, at most 32 characters. */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_.-]{0,31}$/';

    public const MAX_ID_LENGTH = 191;

    private function __construct(public string $type, public string $id) {}

    public static function of(string $type, string|int $id): self
    {
        $id = (string) $id;
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidDataSubject('A data subject type is 1 to 32 lowercase characters, starting with a letter.');
        }
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
            throw new InvalidDataSubject('A data subject id is 1 to '.self::MAX_ID_LENGTH.' characters.');
        }

        return new self($type, $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && hash_equals($this->id, $other->id);
    }
}
