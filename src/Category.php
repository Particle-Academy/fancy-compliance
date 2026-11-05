<?php

declare(strict_types=1);

namespace FancyCompliance;

/**
 * One consent category, as the host configured it.
 *
 * `label`, `description` and every purpose are TRANSLATION KEYS, not text: the
 * server resolves them for the current locale when it can, and the UI resolves
 * them from the host's messages when the server could not.
 */
final readonly class Category
{
    /** @param list<string> $purposes translation keys */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public array $purposes,
        public bool $required,
    ) {}
}
