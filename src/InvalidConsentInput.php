<?php

declare(strict_types=1);

namespace FancyCompliance;

use InvalidArgumentException;

/**
 * A decision or withdrawal that cannot be recorded. Nothing is written.
 *
 * `$code` is a stable string for machines (`unknown_category`,
 * `necessary_not_refusable`, `invalid_source`, `invalid_input`); the HTTP layer
 * answers 422 with it. The exception message starts with the same code.
 */
final class InvalidConsentInput extends InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, string $detail)
    {
        parent::__construct($errorCode.': '.$detail);
    }
}
