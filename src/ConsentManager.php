<?php

declare(strict_types=1);

namespace FancyCompliance;

use Carbon\CarbonImmutable;
use FancyCompliance\Contracts\ConsentGate;
use FancyCompliance\Models\ConsentRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Records, withdraws and answers consent. Behind the `Consent` facade and the
 * {@see ConsentGate} contract.
 *
 * Every write appends one ledger row; nothing here updates or deletes. The
 * current state of a subject is its latest row (highest id), read against the
 * current policy version.
 */
final class ConsentManager implements ConsentGate
{
    /** Sources a caller may name. `login` is written only by the login link. */
    public const SOURCES = ['banner', 'preferences', 'api'];

    /** 32 random bytes, base64url without padding. Anchored, fixed-width: no backtracking. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    private const NEW_TOKEN = 'fancy-compliance.new-guest-token';

    public function __construct(private readonly Container $app) {}

    public function categories(): Categories
    {
        return Categories::fromConfig((array) $this->config('categories', []));
    }

    public function policyVersion(): string
    {
        $version = (string) $this->config('policy_version', '1');
        if ($version === '' || strlen($version) > 64) {
            throw new InvalidCategoryConfiguration('fancy-compliance.policy_version must be 1 to 64 characters.');
        }

        return $version;
    }

    public function allows(string $category, ?Request $request = null): bool
    {
        if (! $this->categories()->has($category)) {
            return false;
        }

        return $this->state($request)->allows($category);
    }

    public function state(?Request $request = null): ConsentState
    {
        $request = $this->request($request);
        $required = $this->categories()->requiredKeys();
        $subject = $this->subject($request);
        $row = $subject === null ? null : $this->latest($subject);
        if ($row === null) {
            return ConsentState::undecided($required);
        }

        return new ConsentState(
            granted: array_values(array_unique([...$required, ...$row->granted])),
            refused: array_values(array_diff($row->refused, $required)),
            required: $required,
            policyVersion: $row->policy_version,
            decidedAt: CarbonImmutable::instance($row->created_at)->utc(),
            source: $row->source,
            current: $row->policy_version === $this->policyVersion(),
        );
    }

    /**
     * Record a decision: `$granted` are the optional categories the person
     * accepted; every other optional category is recorded as refused. Required
     * categories are always granted, whatever is passed.
     *
     * @param  list<string>  $granted
     */
    public function record(array $granted, string $source = 'api', ?Request $request = null): ConsentState
    {
        $request = $this->request($request);
        $this->assertSource($source);
        $categories = $this->categories();
        $granted = $this->assertKnown($granted, $categories);

        $final = array_values(array_filter($categories->keys(), fn (string $key) => $categories->get($key)->required || in_array($key, $granted, true)));
        $this->append($this->subjectOrNewGuest($request), 'decision', $final, $this->complement($final, $categories), $source, $this->policyVersion());

        return $this->state($request);
    }

    /**
     * Withdraw consent for `$categories` (every optional category when null),
     * by appending a new row. Required categories cannot be withdrawn.
     *
     * @param  list<string>|null  $categories
     */
    public function withdraw(?array $categories = null, string $source = 'preferences', ?Request $request = null): ConsentState
    {
        $request = $this->request($request);
        $this->assertSource($source);
        $config = $this->categories();
        $withdrawn = $categories === null ? $config->optionalKeys() : $this->assertKnown($categories, $config);
        foreach ($withdrawn as $key) {
            if ($config->get($key)->required) {
                throw new InvalidConsentInput('necessary_not_refusable', "The [{$key}] category is required and cannot be refused.");
            }
        }

        $current = $this->state($request);
        // A decision under an older policy grants nothing now, so withdrawing from it starts from the required set.
        $base = $current->current ? $current->granted : $config->requiredKeys();
        $final = array_values(array_filter($config->keys(), fn (string $key) => $config->get($key)->required || (in_array($key, $base, true) && ! in_array($key, $withdrawn, true))));
        $this->append($this->subjectOrNewGuest($request), 'withdrawal', $final, $this->complement($final, $config), $source, $this->policyVersion());

        return $this->state($request);
    }

    /**
     * On login, carry a guest's decision over to the user, as a new `link` row
     * on the user. Does nothing when the guest never decided, or when the user
     * already has a decision newer than the guest's. Guest rows are untouched.
     */
    public function linkGuestToUser(Request $request, Authenticatable|string|int $user): bool
    {
        $token = $this->guestToken($request);
        $guest = $token === null ? null : $this->latest(Subject::guest($token));
        if ($guest === null) {
            return false;
        }
        $subject = Subject::user($user instanceof Authenticatable ? $user->getAuthIdentifier() : $user);
        $existing = $this->latest($subject);
        if ($existing !== null && $existing->id > $guest->id) {
            return false;
        }
        $this->append($subject, 'link', $guest->granted, $guest->refused, 'login', $guest->policy_version, $guest->subject_id);

        return true;
    }

    /** Who this request's consent belongs to, or null for a guest who has never decided. */
    public function subject(Request $request): ?Subject
    {
        $user = $request->user($this->config('guard'));
        if ($user instanceof Authenticatable) {
            return Subject::user($user->getAuthIdentifier());
        }
        $token = $this->guestToken($request);

        return $token === null ? null : Subject::guest($token);
    }

    /** The cookie to attach to the response after a guest's first decision; null otherwise. */
    public function pendingGuestCookie(Request $request): ?Cookie
    {
        $token = $request->attributes->get(self::NEW_TOKEN);
        if (! is_string($token)) {
            return null;
        }
        $cookie = (array) $this->config('cookie', []);
        $secure = $cookie['secure'] ?? null;

        return Cookie::create(
            name: $this->cookieName(),
            value: $token,
            expire: Carbon::now()->addDays((int) ($cookie['lifetime_days'] ?? 365)),
            path: (string) ($cookie['path'] ?? '/'),
            domain: $cookie['domain'] ?? null,
            secure: $secure === null ? $request->isSecure() : (bool) $secure,
            httpOnly: true,
            raw: false,
            sameSite: $cookie['same_site'] ?? Cookie::SAMESITE_LAX,
        );
    }

    /**
     * What the UI needs to render without a fetch. Shared as an Inertia prop,
     * served by the `show` route, and printed by `@fancyComplianceProps`.
     *
     * @return array{policyVersion: string, categories: list<array<string, mixed>>, consent: array<string, mixed>|null, needsConsent: bool, routes: array<string, string>|null}
     */
    public function props(?Request $request = null): array
    {
        $state = $this->state($request);

        return [
            'policyVersion' => $this->policyVersion(),
            'categories' => array_map(fn (Category $c) => [
                'key' => $c->key,
                'label' => $c->label,
                'labelText' => $this->text($c->label),
                'description' => $c->description,
                'descriptionText' => $this->text($c->description),
                'purposes' => array_map(fn (string $p) => ['key' => $p, 'text' => $this->text($p)], $c->purposes),
                'required' => $c->required,
            ], $this->categories()->all()),
            'consent' => $state->toArray(),
            'needsConsent' => $state->needsConsent(),
            'routes' => $this->routes(),
        ];
    }

    /** The props as a JSON script tag for a Blade page. Every `<`, `>`, `&` and quote is escaped. */
    public function propsScript(?Request $request = null): string
    {
        $json = json_encode($this->props($request), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return '<script type="application/json" id="fancy-compliance-props">'.$json.'</script>';
    }

    private function request(?Request $request): Request
    {
        return $request ?? $this->app->make('request');
    }

    private function guestToken(Request $request): ?string
    {
        $new = $request->attributes->get(self::NEW_TOKEN);
        if (is_string($new)) {
            return $new;
        }
        $value = $request->cookies->get($this->cookieName());
        if (! is_string($value) || strlen($value) !== 43 || preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function subjectOrNewGuest(Request $request): Subject
    {
        $subject = $this->subject($request);
        if ($subject !== null) {
            return $subject;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $request->attributes->set(self::NEW_TOKEN, $token);

        return Subject::guest($token);
    }

    private function latest(Subject $subject): ?ConsentRecord
    {
        return ConsentRecord::query()
            ->where('subject_type', $subject->type)
            ->where('subject_id', $subject->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $refused
     */
    private function append(Subject $subject, string $action, array $granted, array $refused, string $source, string $policyVersion, ?string $linkedFrom = null): void
    {
        ConsentRecord::query()->create([
            'subject_type' => $subject->type,
            'subject_id' => $subject->id,
            'action' => $action,
            'granted' => array_values($granted),
            'refused' => array_values($refused),
            'policy_version' => $policyVersion,
            'source' => $source,
            'linked_from' => $linkedFrom,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return list<string>
     */
    private function assertKnown(array $keys, Categories $categories): array
    {
        if (! array_is_list($keys) || count($keys) > Categories::MAX) {
            throw new InvalidConsentInput('invalid_input', 'Categories must be a list of at most '.Categories::MAX.' keys.');
        }
        foreach ($keys as $key) {
            if (! is_string($key) || strlen($key) > 32) {
                throw new InvalidConsentInput('invalid_input', 'Each category must be a key of at most 32 characters.');
            }
            if (! $categories->has($key)) {
                throw new InvalidConsentInput('unknown_category', 'Unknown consent category.');
            }
        }

        return array_values(array_unique($keys));
    }

    private function assertSource(string $source): void
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidConsentInput('invalid_source', 'Source must be one of '.implode(', ', self::SOURCES).'.');
        }
    }

    /**
     * @param  list<string>  $granted
     * @return list<string>
     */
    private function complement(array $granted, Categories $categories): array
    {
        return array_values(array_diff($categories->optionalKeys(), $granted));
    }

    /** @return array{show: string, record: string, withdraw: string}|null */
    private function routes(): ?array
    {
        if (! $this->config('routes.enabled', true) || ! $this->app->bound('url')) {
            return null;
        }
        $url = $this->app->make('url');
        $routes = $this->app->make('router')->getRoutes();
        if ($routes->getByName('fancy-compliance.show') === null) {
            // Names given after a route is added are indexed on refresh, which a
            // Laravel app does once routing has loaded. Do it here if it has not.
            $routes->refreshNameLookups();
        }
        foreach (['show', 'record', 'withdraw'] as $name) {
            if ($routes->getByName('fancy-compliance.'.$name) === null) {
                return null;
            }
        }

        return [
            'show' => $url->route('fancy-compliance.show', [], false),
            'record' => $url->route('fancy-compliance.record', [], false),
            'withdraw' => $url->route('fancy-compliance.withdraw', [], false),
        ];
    }

    private function text(string $key): ?string
    {
        if (! $this->app->bound('translator')) {
            return null;
        }
        $text = $this->app->make('translator')->get($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    private function cookieName(): string
    {
        return (string) $this->config('cookie.name', 'fancy_compliance_subject');
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('fancy-compliance.'.$key, $default);
    }
}
