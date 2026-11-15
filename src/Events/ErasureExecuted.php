<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

use FancyCompliance\DataRights\DataSubject;

/** An erasure request ran: `$status` is `executed`, or `partially_executed` when a registrant failed (the audit trail has the failure). */
final readonly class ErasureExecuted
{
    public function __construct(public DataSubject $subject, public string $requestId, public string $pseudonym, public string $status) {}
}
