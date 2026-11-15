<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

use FancyCompliance\DataRights\DataSubject;

/** An erasure request was cancelled during its grace period. No data was touched. */
final readonly class ErasureCancelled
{
    public function __construct(public DataSubject $subject, public string $requestId, public string $pseudonym) {}
}
