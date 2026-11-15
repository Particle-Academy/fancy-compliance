<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

use FancyCompliance\DataRights\DataSubject;

/** An export was requested. The subject is in memory only; nothing here is stored. */
final readonly class ExportRequested
{
    public function __construct(public DataSubject $subject, public string $exportId, public string $format) {}
}
