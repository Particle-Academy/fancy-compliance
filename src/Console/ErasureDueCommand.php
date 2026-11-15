<?php

declare(strict_types=1);

namespace FancyCompliance\Console;

use FancyCompliance\DataRights\ErasureService;
use FancyCompliance\Models\ErasureRequest;
use Illuminate\Console\Command;

/**
 * Executes every erasure request whose grace period has ended. Schedule it
 * (`Schedule::command('fancy-compliance:erasure-due')->hourly()`).
 *
 * Exits non-zero while any request is partially executed, so a scheduler that
 * reads exit codes sees it; `--retry` runs those again (only the registrants
 * that failed). `--dry-run` lists what is due, with each registrant's row
 * count, and changes nothing.
 */
final class ErasureDueCommand extends Command
{
    protected $signature = 'fancy-compliance:erasure-due
        {--dry-run : List the due requests and what each registrant holds; change nothing}
        {--retry : Also resume requests that stopped part-way}';

    protected $description = 'Execute the erasure requests whose grace period has ended';

    public function handle(ErasureService $erasure): int
    {
        $due = $erasure->due();
        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run: nothing is changed.');
            foreach ($due as $request) {
                $this->line("{$request->id}: due ".$request->due_at->utc()->format('Y-m-d\TH:i:s\Z'));
                foreach ($erasure->holdingsFor($request) as $key => $rows) {
                    $this->line("  {$key}: {$rows}");
                }
            }
            if ($due === []) {
                $this->line('Nothing is due.');
            }

            return self::SUCCESS;
        }

        $run = $due;
        if ((bool) $this->option('retry')) {
            $run = [...$run, ...$erasure->unfinished()];
        }
        foreach ($run as $request) {
            $result = $erasure->execute($request->id, force: $request->status !== ErasureRequest::PENDING);
            $this->line("{$request->id}: ".($result?->status ?? 'missing'));
        }

        $unfinished = $erasure->unfinished();
        if ($unfinished !== []) {
            $this->error(count($unfinished).' erasure request(s) are partially executed. The failures are in fancy_compliance_erasures; fix them and run with --retry.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
