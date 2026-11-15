<?php

declare(strict_types=1);

namespace FancyCompliance\Jobs;

use FancyCompliance\DataRights\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Builds one export on a queue, when `data_rights.exports.queue.enabled` is
 * on. Carries only the export id: the subject stays encrypted in the row, so
 * nothing personal sits in the queue payload. Building is a no-op for an
 * export that is no longer pending, so a retried job is harmless.
 */
final class BuildExport implements ShouldQueue
{
    /** @var string|null */
    public $connection = null;

    /** @var string|null */
    public $queue = null;

    public function __construct(public string $exportId) {}

    public function handle(ExportService $exports): void
    {
        $exports->build($this->exportId);
    }
}
