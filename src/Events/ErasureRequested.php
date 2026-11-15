<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

use FancyCompliance\DataRights\DataSubject;

/** An erasure was requested. Lock the account during the grace period here, if your app wants that; the package does not assume it. */
final readonly class ErasureRequested
{
    public function __construct(public DataSubject $subject, public string $requestId, public string $pseudonym, public \DateTimeInterface $dueAt) {}
}
