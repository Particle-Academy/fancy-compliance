<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use FancyCompliance\Console\RetentionCommand;
use FancyCompliance\Events\RetentionPruned;
use FancyCompliance\Facades\Retention;
use FancyCompliance\Retention\InvalidRetentionPolicy;
use FancyCompliance\Retention\RetentionOutcome;
use FancyCompliance\Retention\RetentionPolicy;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Phase 2 of CC-fancy-0038: one registry of retention policies and one command
 * that runs them. The consent ledger's prune is the first registered policy;
 * other packages register their own in their own releases.
 */

/** @return array{0: int, 1: string} exit code and output */
function runRetention(array $input = []): array
{
    $command = app(RetentionCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;
    $code = $command->run(new ArrayInput($input), $output);

    return [$code, $output->fetch()];
}

/** Two ledger rows for one guest a day apart, then a year passes. */
function seedOldLedger(): void
{
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    $token = consent()->pendingGuestCookie(app('request'))->getValue();
    Carbon::setTestNow('2026-11-06 12:00:00');
    makeRequest('POST', '/', [GUEST_COOKIE => $token]);
    consent()->withdraw(null, 'preferences');
    Carbon::setTestNow('2027-11-06 12:00:00');
}

/** A cutoff policy over an in-memory list of timestamps, recording what it was asked. */
function fakeStore(array &$items, array &$calls): array
{
    return [
        'prune' => function (CarbonImmutable $before) use (&$items, &$calls): int {
            $calls[] = ['prune', $before->toIso8601ZuluString()];
            $old = array_filter($items, fn (string $at) => CarbonImmutable::parse($at)->lt($before));
            $items = array_values(array_diff($items, $old));

            return count($old);
        },
        'count' => function (CarbonImmutable $before) use (&$items, &$calls): int {
            $calls[] = ['count', $before->toIso8601ZuluString()];

            return count(array_filter($items, fn (string $at) => CarbonImmutable::parse($at)->lt($before)));
        },
    ];
}

it('registers the consent ledger as the policy fancy-compliance.ledger', function (): void {
    complianceApp();

    expect(Retention::keys())->toBe(['fancy-compliance.ledger']);
});

it('leaves the ledger alone while its retention is null, which is the default', function (): void {
    complianceApp();
    seedOldLedger();

    [$code, $output] = runRetention();

    expect($code)->toBe(0)
        ->and(ledgerRows())->toHaveCount(2)
        ->and($output)->toContain('fancy-compliance.ledger')
        ->and($output)->toContain('off');
});

it('prunes the ledger through the registry, keeping each subject\'s latest row', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    seedOldLedger();

    [$code] = runRetention();

    expect($code)->toBe(0)
        ->and(ledgerRows())->toHaveCount(1)
        ->and(ledgerRows()[0]->action)->toBe('withdrawal');
});

it('deletes nothing on a dry run, and says what it would delete', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    seedOldLedger();

    [$code, $output] = runRetention(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and(ledgerRows())->toHaveCount(2)
        ->and($output)->toContain('would delete 1');
});

it('keeps fancy-compliance:prune as an alias that runs only the ledger policy', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    seedOldLedger();
    $items = ['2020-01-01T00:00:00Z'];
    $calls = [];
    $store = fakeStore($items, $calls);
    Retention::policy('host.audit', days: 1, prune: $store['prune'], count: $store['count']);

    expect(runPrune())->toBe(0)
        ->and(ledgerRows())->toHaveCount(1)
        ->and($items)->toHaveCount(1)
        ->and($calls)->toBe([]);
});

it('runs a host policy only before its cutoff, and reports the count', function (): void {
    complianceApp();
    Carbon::setTestNow('2026-11-14 00:00:00');
    $items = ['2026-11-01T00:00:00Z', '2026-11-10T00:00:00Z', '2026-11-13T00:00:00Z'];
    $calls = [];
    $store = fakeStore($items, $calls);
    Retention::policy('host.audit', days: 7, prune: $store['prune'], count: $store['count']);

    [$code, $output] = runRetention();

    expect($code)->toBe(0)
        ->and($items)->toBe(['2026-11-10T00:00:00Z', '2026-11-13T00:00:00Z'])
        ->and($calls)->toBe([['prune', '2026-11-07T00:00:00Z']])
        ->and($output)->toContain('host.audit')
        ->and($output)->toContain('deleted 1');
});

it('never calls prune on a dry run, and skips a policy that cannot count', function (): void {
    complianceApp();
    $pruned = false;
    Retention::policy('host.blind', days: 1, prune: function () use (&$pruned): int {
        $pruned = true;

        return 9;
    });

    [$code, $output] = runRetention(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($pruned)->toBeFalse()
        ->and($output)->toContain('host.blind')
        ->and($output)->toContain('cannot count');
});

it('reads a policy\'s days when it runs, so a closure follows config', function (): void {
    complianceApp(['host.days' => null]);
    $items = ['2000-01-01T00:00:00Z'];
    $calls = [];
    $store = fakeStore($items, $calls);
    Retention::policy('host.audit', days: fn () => config('host.days'), prune: $store['prune'], count: $store['count']);

    runRetention();
    expect($items)->toHaveCount(1);

    config(['host.days' => 30]);
    runRetention();
    expect($items)->toBe([]);
});

it('runs only the named policies with --only', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    seedOldLedger();
    $items = ['2000-01-01T00:00:00Z'];
    $calls = [];
    $store = fakeStore($items, $calls);
    Retention::policy('host.audit', days: 1, prune: $store['prune'], count: $store['count']);

    [$code] = runRetention(['--only' => ['host.audit']]);

    expect($code)->toBe(0)
        ->and($items)->toBe([])
        ->and(ledgerRows())->toHaveCount(2);
});

it('fails loudly on an unknown --only key and runs nothing', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    seedOldLedger();

    [$code, $output] = runRetention(['--only' => ['fancy-compliance.ledger', 'nope']]);

    expect($code)->toBe(1)
        ->and($output)->toContain('nope')
        ->and(ledgerRows())->toHaveCount(2);
});

it('reports a failing policy, still runs the others, and exits non-zero', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    seedOldLedger();
    Retention::policy('host.broken', days: 1, prune: fn (): int => throw new RuntimeException('disk on fire'));

    [$code, $output] = runRetention();

    expect($code)->toBe(1)
        ->and($output)->toContain('host.broken')
        ->and($output)->toContain('disk on fire')
        ->and(ledgerRows())->toHaveCount(1);
});

it('treats invalid days as a failure, not as "keep everything"', function (mixed $days): void {
    complianceApp();
    $called = false;
    Retention::policy('host.audit', days: $days, prune: function () use (&$called): int {
        $called = true;

        return 0;
    });

    [$code, $output] = runRetention();

    expect($code)->toBe(1)
        ->and($called)->toBeFalse()
        ->and($output)->toContain('host.audit');
})->with([
    'zero' => [0],
    'negative' => [-5],
    'text' => ['soon'],
    'float' => [1.5],
]);

it('accepts days from env as a digit string', function (): void {
    complianceApp(['fancy-compliance.retention.days' => '30']);
    seedOldLedger();

    expect(runRetention()[0])->toBe(0)
        ->and(ledgerRows())->toHaveCount(1);
});

it('accepts a policy object for a package whose windows are its own', function (): void {
    complianceApp();
    $seen = [];
    Retention::register(new class($seen) implements RetentionPolicy
    {
        public function __construct(private array &$seen) {}

        public function key(): string
        {
            return 'host.windows';
        }

        public function run(bool $dryRun): RetentionOutcome
        {
            $this->seen[] = $dryRun;

            return RetentionOutcome::counted(['events' => 4, 'sessions' => 1]);
        }
    });

    [, $dry] = runRetention(['--dry-run' => true, '--only' => ['host.windows']]);
    [, $real] = runRetention(['--only' => ['host.windows']]);

    expect($seen)->toBe([true, false])
        ->and($dry)->toContain('would delete 5')
        ->and($real)->toContain('deleted 5');
});

it('refuses a duplicate or malformed policy key', function (string $key): void {
    complianceApp();

    expect(fn () => Retention::policy($key, days: 1, prune: fn (): int => 0))->toThrow(InvalidRetentionPolicy::class);
})->with([
    'duplicate' => ['fancy-compliance.ledger'],
    'empty' => [''],
    'spaces' => ['host audit'],
    'upper' => ['Host.Audit'],
]);

it('dispatches RetentionPruned after a real run, never after a dry run', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    seedOldLedger();
    $events = [];
    app('events')->listen(RetentionPruned::class, function (RetentionPruned $e) use (&$events): void {
        $events[] = [$e->key, $e->counts];
    });

    runRetention(['--dry-run' => true]);
    expect($events)->toBe([]);

    runRetention();
    expect($events)->toBe([['fancy-compliance.ledger', ['consents' => 1]]]);
});
