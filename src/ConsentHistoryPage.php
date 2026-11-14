<?php

declare(strict_types=1);

namespace FancyCompliance;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A page of a subject's consent history, newest first. `nextBefore` is the id
 * to pass as `before` for the next (older) page, or null on the last page.
 *
 * @implements IteratorAggregate<int, ConsentHistoryEntry>
 */
final readonly class ConsentHistoryPage implements Countable, IteratorAggregate
{
    /** @param list<ConsentHistoryEntry> $entries */
    public function __construct(public array $entries, public ?int $nextBefore) {}

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return Traversable<int, ConsentHistoryEntry> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }

    /** @return array{entries: list<array<string, mixed>>, nextBefore: int|null} */
    public function toArray(): array
    {
        return [
            'entries' => array_map(static fn (ConsentHistoryEntry $e): array => $e->toArray(), $this->entries),
            'nextBefore' => $this->nextBefore,
        ];
    }
}
