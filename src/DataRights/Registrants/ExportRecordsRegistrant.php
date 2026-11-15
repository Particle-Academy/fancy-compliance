<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights\Registrants;

use FancyCompliance\DataRights\DataRegistrant;
use FancyCompliance\DataRights\ErasurePlan;
use FancyCompliance\DataRights\ExportService;
use FancyCompliance\DataRights\ExportWriter;
use FancyCompliance\DataRights\SubjectKeys;
use FancyCompliance\Models\DataExport;
use Illuminate\Contracts\Container\Container;

/**
 * This package's export records, `fancy-compliance.exports`. They are keyed by
 * the subject itself (a keyed hash of it), so this reads
 * {@see SubjectKeys::subject()}. Erasure deletes the records and their files.
 * An export writes nothing about earlier exports: they are the person's
 * requests, not their data.
 */
final class ExportRecordsRegistrant implements DataRegistrant
{
    public const KEY = 'fancy-compliance.exports';

    public function __construct(private readonly Container $app) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): string
    {
        return PackageVersion::get();
    }

    public function export(SubjectKeys $keys, ExportWriter $writer): void {}

    public function erase(SubjectKeys $keys, ErasurePlan $plan): void
    {
        $subject = $keys->subject();
        if ($subject !== null) {
            $plan->delete(DataExport::TABLE, $this->app->make(ExportService::class)->eraseFor($subject));
        }
    }

    public function holds(SubjectKeys $keys): int
    {
        $subject = $keys->subject();

        return $subject === null ? 0 : $this->app->make(ExportService::class)->countFor($subject);
    }
}
