<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

use FancyCompliance\DataRights\DataSubject;

/** An export was downloaded by an authenticated subject with a valid single-use token. */
final readonly class ExportDownloaded
{
    public function __construct(public DataSubject $actor, public string $exportId) {}
}
