<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

/**
 * What one registrant did to one subject's data, as it tells it: rows deleted,
 * anonymised, or retained with a reason. It becomes the registrant's entry in
 * the erasure audit trail and in the view the person sees.
 *
 * Retained rows are `rekeyed` when the subject key was replaced by
 * {@see self::pseudonym()} and kept unchanged otherwise (a hash-chained log,
 * whose rows cannot be rewritten without breaking its verification).
 */
final class ErasurePlan
{
    private const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]{0,127}$/';

    /** @var array<string, int> */
    private array $deleted = [];

    /** @var array<string, int> */
    private array $anonymised = [];

    /** @var list<array{table: string, rows: int, reason: string, rekeyed: bool}> */
    private array $retained = [];

    public function __construct(private readonly string $pseudonym) {}

    /** The subject's keyed-hash pseudonym: put it where a row must keep a subject key. */
    public function pseudonym(): string
    {
        return $this->pseudonym;
    }

    public function delete(string $table, int $rows): self
    {
        $this->assert($table, $rows);
        if ($rows > 0) {
            $this->deleted[$table] = ($this->deleted[$table] ?? 0) + $rows;
        }

        return $this;
    }

    public function anonymise(string $table, int $rows): self
    {
        $this->assert($table, $rows);
        if ($rows > 0) {
            $this->anonymised[$table] = ($this->anonymised[$table] ?? 0) + $rows;
        }

        return $this;
    }

    /** A reason is required: it is what the person reads about why the rows stay. */
    public function retain(string $table, int $rows, string $reason, bool $rekeyed = false): self
    {
        $this->assert($table, $rows);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('A retained table needs a reason of 1 to 500 characters.');
        }
        if ($rows > 0) {
            $this->retained[] = ['table' => $table, 'rows' => $rows, 'reason' => $reason, 'rekeyed' => $rekeyed];
        }

        return $this;
    }

    /** @return array{deleted: array<string, int>, anonymised: array<string, int>, retained: list<array{table: string, rows: int, reason: string, rekeyed: bool}>} */
    public function toArray(): array
    {
        return ['deleted' => $this->deleted, 'anonymised' => $this->anonymised, 'retained' => $this->retained];
    }

    private function assert(string $table, int $rows): void
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new \InvalidArgumentException("[{$table}] is not a table name.");
        }
        if ($rows < 0) {
            throw new \InvalidArgumentException('A row count cannot be negative.');
        }
    }
}
