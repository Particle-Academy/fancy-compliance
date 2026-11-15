<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use RuntimeException;

/** An export grew past `exports.max_bytes` or `exports.max_files`. The export fails with this code. */
final class ExportLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("The export is past its {$errorCode} cap.");
    }
}
