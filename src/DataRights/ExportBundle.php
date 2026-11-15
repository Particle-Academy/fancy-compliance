<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Everything the registrants wrote for one export, and the two formats it
 * renders to (docs/data-rights-protocol.md, "The export file").
 */
final class ExportBundle
{
    public const FORMAT = 'fancy-compliance.export';

    public const VERSION = 1;

    /** @var array<string, array<string, array{data: mixed, encoded: string, rows: int|null}>> */
    private array $sections = [];

    /** @var array<string, array<string, string>> */
    private array $files = [];

    /** @var list<string> */
    private array $registrants = [];

    private int $bytes = 0;

    private int $count = 0;

    public function __construct(private readonly int $maxBytes, private readonly int $maxFiles) {}

    public function writerFor(string $registrant): ExportWriter
    {
        $this->registrants[] = $registrant;

        return new ExportWriter($registrant, $this);
    }

    public function addSection(string $registrant, string $section, mixed $data, string $encoded, ?int $rows): void
    {
        if (isset($this->sections[$registrant][$section])) {
            throw new InvalidArgumentException("Section [{$section}] was written twice.");
        }
        $this->count(strlen($encoded));
        $this->sections[$registrant][$section] = ['data' => $data, 'encoded' => $encoded, 'rows' => $rows];
    }

    public function addFile(string $registrant, string $name, string $contents): void
    {
        if (isset($this->files[$registrant][$name])) {
            throw new InvalidArgumentException("File [{$name}] was written twice.");
        }
        $this->count(strlen($contents));
        $this->files[$registrant][$name] = $contents;
    }

    /** @param array{type: string, id: string} $subject */
    public function toJson(array $subject, string $generatedAt): string
    {
        $sections = [];
        foreach ($this->sections as $registrant => $parts) {
            foreach ($parts as $name => $part) {
                $sections[$registrant][$name] = $part['data'];
            }
        }
        $omitted = [];
        foreach ($this->files as $registrant => $files) {
            foreach ($files as $name => $contents) {
                $omitted[] = ['registrant' => $registrant, 'name' => $name, 'bytes' => strlen($contents)];
            }
        }

        $json = json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'subject' => $subject,
            'generated_at' => $generatedAt,
            'sections' => (object) $sections,
            'files_omitted' => $omitted,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT);
        $this->assertFinalSize(strlen($json));

        return $json;
    }

    /** @param array{type: string, id: string} $subject */
    public function toZip(array $subject, string $generatedAt): string
    {
        if (! DataRightsConfig::zipAvailable()) {
            throw new ExportLimitExceeded('zip_unavailable');
        }
        $entries = [];
        foreach ($this->sections as $registrant => $parts) {
            foreach ($parts as $name => $part) {
                $entries[] = ['path' => "{$registrant}/{$name}.json", 'content' => $part['encoded'], 'rows' => $part['rows']];
            }
        }
        foreach ($this->files as $registrant => $files) {
            foreach ($files as $name => $contents) {
                $entries[] = ['path' => "{$registrant}/files/{$name}", 'content' => $contents, 'rows' => null];
            }
        }
        $manifest = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'subject' => $subject,
            'generated_at' => $generatedAt,
            'registrants' => $this->registrants,
            'files' => array_map(static fn (array $e): array => [
                'path' => $e['path'],
                'sha256' => hash('sha256', $e['content']),
                'bytes' => strlen($e['content']),
                'rows' => $e['rows'],
            ], $entries),
        ];

        $path = tempnam(sys_get_temp_dir(), 'fancy-compliance-export');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the export archive.');
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            foreach ($entries as $entry) {
                $zip->addFromString($entry['path'], $entry['content']);
            }
            if ($zip->close() !== true) {
                throw new RuntimeException('Could not write the export archive.');
            }
            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
        $this->assertFinalSize(strlen($bytes));

        return $bytes;
    }

    private function count(int $bytes): void
    {
        $this->count++;
        $this->bytes += $bytes;
        if ($this->count > $this->maxFiles) {
            throw new ExportLimitExceeded('too_many_files');
        }
        if ($this->bytes > $this->maxBytes) {
            throw new ExportLimitExceeded('too_large');
        }
    }

    private function assertFinalSize(int $bytes): void
    {
        if ($bytes > $this->maxBytes) {
            throw new ExportLimitExceeded('too_large');
        }
    }
}
