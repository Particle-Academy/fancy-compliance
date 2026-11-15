<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

use FancyCompliance\DataRights\DataSubject;

/** An export could not be built. `$registrant` names the one that threw, when one did. */
final readonly class ExportFailed
{
    public function __construct(public DataSubject $subject, public string $exportId, public string $error, public ?string $registrant) {}
}
