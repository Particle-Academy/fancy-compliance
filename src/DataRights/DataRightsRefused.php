<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use RuntimeException;

/**
 * A data-rights request the package will not carry out, with the protocol's
 * error code and HTTP status (docs/data-rights-protocol.md). The controllers
 * render it; PHP callers can read the same fields.
 */
final class DataRightsRefused extends RuntimeException
{
    /** @param array<string, mixed> $extra fields added to the error body */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct("Data rights request refused: {$errorCode}.");
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['error' => $this->errorCode, ...$this->extra];
    }
}
