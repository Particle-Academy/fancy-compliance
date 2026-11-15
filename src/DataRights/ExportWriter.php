<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use InvalidArgumentException;

/**
 * What one registrant hands to an export: JSON sections and attached files.
 * Every write is checked against the export's size and file caps as it is
 * made, so an oversized export stops early and fails loudly.
 *
 * In a JSON export, attached files cannot travel (no binary in the JSON); they
 * are named in `files_omitted`, and the ZIP format carries them.
 */
final class ExportWriter
{
    private const SECTION_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /** A file name, never a path: no slash, no leading dot. */
    private const FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/';

    public function __construct(private readonly string $registrant, private readonly ExportBundle $bundle) {}

    /**
     * One section: `<registrant>/<section>.json` in a ZIP, `sections.<registrant>.<section>` in JSON.
     *
     * @param  int|null  $rows  how many records it holds, for the manifest; a list counts itself
     */
    public function json(string $section, mixed $data, ?int $rows = null): self
    {
        if (preg_match(self::SECTION_PATTERN, $section) !== 1) {
            throw new InvalidArgumentException("[{$section}] is not a section name: 1 to 64 lowercase letters, digits, dots, dashes or underscores.");
        }
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $rows ??= is_array($data) && array_is_list($data) ? count($data) : null;
        $this->bundle->addSection($this->registrant, $section, $data, $encoded, $rows);

        return $this;
    }

    /** An attached file, such as a sealed PDF. */
    public function file(string $name, string $contents): self
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidArgumentException("[{$name}] is not a file name: no paths, no leading dot, at most 128 characters.");
        }
        $this->bundle->addFile($this->registrant, $name, $contents);

        return $this;
    }
}
