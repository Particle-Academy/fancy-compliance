<?php

declare(strict_types=1);

namespace FancyCompliance;

use DateTimeInterface;
use FancyCompliance\Models\ConsentRecord;

/**
 * One ledger row as its subject may see it. The subject key is left out, and a
 * login link says only that it was linked: the guest hash it came from is never
 * shown, so a history cannot be replayed as somebody's cookie.
 */
final readonly class ConsentHistoryEntry
{
    /**
     * @param  list<string>  $granted
     * @param  list<string>  $refused
     */
    public function __construct(
        public int $id,
        public string $action,
        public array $granted,
        public array $refused,
        public string $policyVersion,
        public string $source,
        public DateTimeInterface $at,
        public bool $linked,
    ) {}

    public static function fromRecord(ConsentRecord $row): self
    {
        return new self(
            id: (int) $row->id,
            action: $row->action,
            granted: array_values($row->granted),
            refused: array_values($row->refused),
            policyVersion: $row->policy_version,
            source: $row->source,
            at: $row->created_at->toImmutable()->utc(),
            linked: $row->linked_from !== null,
        );
    }

    /** @return array{id: int, action: string, granted: list<string>, refused: list<string>, policyVersion: string, source: string, at: string, linked: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'granted' => $this->granted,
            'refused' => $this->refused,
            'policyVersion' => $this->policyVersion,
            'source' => $this->source,
            'at' => $this->at->format('Y-m-d\TH:i:s\Z'),
            'linked' => $this->linked,
        ];
    }
}
