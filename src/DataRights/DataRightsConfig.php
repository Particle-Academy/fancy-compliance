<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use Illuminate\Contracts\Config\Repository;
use ZipArchive;

/**
 * Every data-rights setting, read and checked in one place. A key the host's
 * config does not have takes the default (a config published by 0.1 has no
 * export or erasure keys, and Laravel merges package config shallowly). A key
 * it HAS but that cannot be read safely throws: a bad limit fails the request,
 * it never means "no limit".
 */
final class DataRightsConfig
{
    public const CONSENTS_PSEUDONYMISE = 'pseudonymise';

    public const CONSENTS_DELETE = 'delete';

    private const BASE = 'fancy-compliance.data_rights.';

    public function __construct(private readonly Repository $config) {}

    public function reauthRequired(): bool
    {
        return $this->bool('reauth.required', true);
    }

    public function reauthWindowMinutes(): int
    {
        return $this->int('reauth.window_minutes', 10, 1);
    }

    public function exportsEnabled(): bool
    {
        return $this->bool('exports.enabled', true);
    }

    /** @return list<string> what this host can build, in the order configured */
    public function exportFormats(): array
    {
        $formats = $this->value('exports.formats', ['json', 'zip']);
        if (! is_array($formats)) {
            throw new InvalidDataRightsConfiguration('fancy-compliance.data_rights.exports.formats must be a list of "json" and "zip".');
        }
        $out = [];
        foreach ($formats as $format) {
            if (! in_array($format, ['json', 'zip'], true)) {
                throw new InvalidDataRightsConfiguration('fancy-compliance.data_rights.exports.formats must be a list of "json" and "zip".');
            }
            if ($format === 'zip' && ! self::zipAvailable()) {
                continue;
            }
            $out[] = $format;
        }

        return array_values(array_unique($out));
    }

    public static function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function exportDisk(): string
    {
        return $this->string('exports.disk', 'local');
    }

    public function exportDirectory(): string
    {
        $dir = trim($this->string('exports.directory', 'fancy-compliance/exports'), '/');
        if ($dir === '' || str_contains($dir, '..')) {
            throw new InvalidDataRightsConfiguration('fancy-compliance.data_rights.exports.directory must be a relative path.');
        }

        return $dir;
    }

    public function exportTtlHours(): int
    {
        return $this->int('exports.ttl_hours', 24, 1);
    }

    public function deleteAfterDownload(): bool
    {
        return $this->bool('exports.delete_after_download', false);
    }

    public function exportsPerDay(): int
    {
        return $this->int('exports.per_day', 3, 1);
    }

    public function exportMaxBytes(): int
    {
        return $this->int('exports.max_bytes', 52_428_800, 1);
    }

    public function exportMaxFiles(): int
    {
        return $this->int('exports.max_files', 1000, 1);
    }

    /** Days an export's record is kept (its file goes at the TTL); null keeps records. */
    public function exportRecordDays(): ?int
    {
        if ($this->value('exports.record_days', 30) === null) {
            return null;
        }

        return $this->int('exports.record_days', 30, 1);
    }

    public function queueEnabled(): bool
    {
        return $this->bool('exports.queue.enabled', false);
    }

    public function queueConnection(): ?string
    {
        $value = $this->value('exports.queue.connection', null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function queueName(): ?string
    {
        $value = $this->value('exports.queue.name', null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function erasureEnabled(): bool
    {
        return $this->bool('erasure.enabled', true);
    }

    /** 0 is allowed: the request executes as soon as it is made. */
    public function graceDays(): int
    {
        return $this->int('erasure.grace_days', 30, 0);
    }

    public function erasuresPerDay(): int
    {
        return $this->int('erasure.per_day', 3, 1);
    }

    /** What happens to the consent ledger on erasure: re-keyed to the pseudonym (default) or deleted. */
    public function consentsMode(): string
    {
        $mode = $this->value('erasure.consents', self::CONSENTS_PSEUDONYMISE);
        if (! in_array($mode, [self::CONSENTS_PSEUDONYMISE, self::CONSENTS_DELETE], true)) {
            throw new InvalidDataRightsConfiguration('fancy-compliance.data_rights.erasure.consents must be "pseudonymise" or "delete".');
        }

        return $mode;
    }

    /** The decoded erasure key (at least 32 bytes), or null when none usable is set. */
    public function erasureKey(): ?string
    {
        $key = $this->value('erasure.key', null);
        if (! is_string($key) || $key === '') {
            return null;
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
            if ($key === false) {
                return null;
            }
        }

        return strlen($key) >= 32 ? $key : null;
    }

    private function value(string $key, mixed $default): mixed
    {
        $full = self::BASE.$key;

        return $this->config->has($full) ? $this->config->get($full) : $default;
    }

    private function int(string $key, int $default, int $min): int
    {
        $value = $this->value($key, $default);
        if (is_string($value) && preg_match('/^[0-9]{1,9}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $min) {
            throw new InvalidDataRightsConfiguration("fancy-compliance.data_rights.{$key} must be a whole number, {$min} or more.");
        }

        return $value;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->value($key, $default);
        if (! is_bool($value)) {
            throw new InvalidDataRightsConfiguration("fancy-compliance.data_rights.{$key} must be true or false.");
        }

        return $value;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->value($key, $default);
        if (! is_string($value) || $value === '') {
            throw new InvalidDataRightsConfiguration("fancy-compliance.data_rights.{$key} must be a non-empty string.");
        }

        return $value;
    }
}
