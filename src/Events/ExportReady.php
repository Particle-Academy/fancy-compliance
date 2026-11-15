<?php

declare(strict_types=1);

namespace FancyCompliance\Events;

use FancyCompliance\DataRights\DataSubject;

/** An export was built. Tell the person to sign in to download it: never mail a link that works on its own. */
final readonly class ExportReady
{
    public function __construct(public DataSubject $subject, public string $exportId, public string $format, public int $bytes) {}
}
