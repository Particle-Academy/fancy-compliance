<?php

declare(strict_types=1);

namespace FancyCompliance\Retention;

use FancyCompliance\DataRights\DataRightsConfig;
use FancyCompliance\DataRights\ExportService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;

/**
 * Export records, `fancy-compliance.exports`, in two classes:
 *
 * - `files`: export files past their time to live (`exports.ttl_hours`),
 *   deleted; the record says `expired`. A download after the TTL is refused
 *   whether or not this has run.
 * - `records`: export records older than `exports.record_days` (default 30;
 *   null keeps them), deleted with any file.
 */
final class ExportRetention implements RetentionPolicy
{
    public const KEY = 'fancy-compliance.exports';

    public function __construct(private readonly Container $app) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function run(bool $dryRun): RetentionOutcome
    {
        $exports = $this->app->make(ExportService::class);
        $days = (new DataRightsConfig($this->app->make('config')))->exportRecordDays();
        $files = $exports->expireFiles($dryRun);
        $records = $days === null ? 0 : $exports->pruneRecords(Carbon::now()->subDays($days), $dryRun);

        return RetentionOutcome::counted(['files' => $files, 'records' => $records]);
    }
}
