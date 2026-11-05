<?php

declare(strict_types=1);

use FancyCompliance\ConsentManager;
use FancyCompliance\Contracts\ConsentGate;
use FancyCompliance\Facades\Consent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;

it('binds the ConsentGate contract and the facade to one manager', function (): void {
    complianceApp();
    makeRequest();

    expect(app(ConsentGate::class))->toBe(app(ConsentManager::class))
        ->and(Consent::allows('analytics'))->toBeFalse()
        ->and(Consent::allows('necessary'))->toBeTrue();
});

it('renders a Blade @consent block only after consent is given', function (): void {
    complianceApp();
    makeRequest();
    $template = "@consent('analytics')<script src=\"/tracker.js\"></script>@endconsent";

    expect(Blade::render($template))->toBe('');

    consent()->record(['analytics'], 'banner');
    expect(Blade::render($template))->toBe('<script src="/tracker.js"></script>');
});

it('emits the props for a non-Inertia page as escaped JSON', function (): void {
    complianceApp(['fancy-compliance.categories.analytics.label' => '</script><script>alert(1)</script>']);
    makeRequest();

    $html = Blade::render('@fancyComplianceProps');

    expect($html)->toStartWith('<script type="application/json" id="fancy-compliance-props">')
        ->and($html)->not->toContain('</script><script>')
        ->and(substr_count($html, '</script>'))->toBe(1);

    $json = substr($html, strlen('<script type="application/json" id="fancy-compliance-props">'), -strlen('</script>'));
    expect(json_decode($json, true)['categories'][1]['label'])->toBe('</script><script>alert(1)</script>');
});

it('shares the same props with Inertia when Inertia is installed', function (): void {
    complianceApp();
    makeRequest();

    expect(Inertia\Inertia::$shared)->toHaveKey('fancyCompliance');
    $value = Inertia\Inertia::$shared['fancyCompliance'];
    expect($value)->toBeInstanceOf(Closure::class)
        ->and($value())->toBe(consent()->props());
});

it('lets the host rename or turn off the Inertia prop', function (): void {
    complianceApp(['fancy-compliance.inertia.key' => 'consent']);
    expect(Inertia\Inertia::$shared)->toHaveKey('consent');

    Inertia\Inertia::$shared = [];
    complianceApp(['fancy-compliance.inertia.share' => false]);
    expect(Inertia\Inertia::$shared)->toBe([]);
});

it('resolves the shipped English text for the default categories', function (): void {
    complianceApp();
    makeRequest();

    $analytics = consent()->props()['categories'][1];

    expect($analytics['label'])->toBe('fancy-compliance::categories.analytics.label')
        ->and($analytics['labelText'])->toBe('Analytics')
        ->and($analytics['descriptionText'])->toBeString()->not->toBe('')
        ->and($analytics['purposes'][0]['key'])->toBe('fancy-compliance::categories.analytics.purposes.usage')
        ->and($analytics['purposes'][0]['text'])->toBeString()->not->toBe('');
});

it('sends null text for a key nobody translated, so the UI falls back to its own messages', function (): void {
    complianceApp(['fancy-compliance.categories.analytics.label' => 'host.consent.analytics']);
    makeRequest();

    expect(consent()->props()['categories'][1]['labelText'])->toBeNull();
});

it('keeps every ledger row by default, because consent records are evidence', function (): void {
    complianceApp();
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    Carbon::setTestNow('2030-01-01 00:00:00');
    consent()->withdraw(null, 'preferences');

    expect(runPrune())->toBe(0)
        ->and(ledgerRows())->toHaveCount(2);
});

it('prunes rows past the retention window but keeps each subject\'s latest row', function (): void {
    complianceApp(['fancy-compliance.retention.days' => 30]);
    makeRequest('POST');
    consent()->record(['analytics'], 'banner');
    $token = consent()->pendingGuestCookie(app('request'))->getValue();
    Carbon::setTestNow('2026-11-06 12:00:00');
    makeRequest('POST', '/', [GUEST_COOKIE => $token]);
    consent()->withdraw(null, 'preferences');

    makeRequest('POST');
    consent()->record(['marketing'], 'banner');

    Carbon::setTestNow('2027-06-01 00:00:00');
    expect(runPrune())->toBe(0);

    $rows = ledgerRows();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->action)->toBe('withdrawal')
        ->and(json_decode($rows[1]->granted, true))->toBe(['necessary', 'marketing']);
});
