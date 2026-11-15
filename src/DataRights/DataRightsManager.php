<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use Closure;
use FancyCompliance\ConsentHistoryPage;
use FancyCompliance\ConsentManager;
use FancyCompliance\Subject;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

/**
 * The host's answers, behind the `DataRights` facade:
 *
 * - **who is asking** (`subjects`): a request to a {@see DataSubject}, or null;
 * - **may they** (`authorize`): actor, target and right (`history`, `export`,
 *   `erase`) to a yes or no. The default allows only a subject acting for
 *   itself, so a staff path exists only when the host writes one;
 * - **where is their data** (`resolveFor`): a subject to its {@see SubjectKeys};
 * - **did they just prove it is them** (`confirmRecent`): required before an
 *   export or an erasure is requested, unless the host turns it off;
 * - **may this one be erased now** (`blockErasure`), and **what to do when it
 *   is requested** (`onErasureRequested`).
 *
 * Plus the registry of {@see DataRegistrant}s: each package's exporter and
 * anonymiser, and the host's own. The consent ledger's and the export
 * records' are registered first, by this package.
 *
 * With no subject resolver, data-rights routes answer 404: fail closed.
 */
final class DataRightsManager
{
    public const RIGHT_HISTORY = 'history';

    public const RIGHT_EXPORT = 'export';

    public const RIGHT_ERASE = 'erase';

    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    private const RESERVED_PREFIX = 'fancy-compliance.';

    private ?Closure $subjects = null;

    private ?Closure $authorizer = null;

    private ?Closure $keys = null;

    private ?Closure $confirmer = null;

    private ?Closure $blocker = null;

    /** @var list<Closure> */
    private array $onErasure = [];

    /** @var array<string, DataRegistrant> */
    private array $registrants = [];

    public function __construct(private readonly ConsentManager $consent, private readonly Container $app) {}

    /** @param callable(Request): ?DataSubject $resolver */
    public function subjects(callable $resolver): void
    {
        $this->subjects = Closure::fromCallable($resolver);
    }

    /** @param callable(DataSubject, DataSubject, string): bool $authorizer */
    public function authorize(callable $authorizer): void
    {
        $this->authorizer = Closure::fromCallable($authorizer);
    }

    /** @param callable(DataSubject): SubjectKeys $resolver */
    public function resolveFor(callable $resolver): void
    {
        $this->keys = Closure::fromCallable($resolver);
    }

    /**
     * The host's re-authentication check: Laravel's `password.confirm`, a
     * passkey step-up, or anything else. Without one, the default reads the
     * session's `auth.password_confirmed_at` (what `password.confirm` writes)
     * against `data_rights.reauth.window_minutes`.
     *
     * @param  callable(Request): bool  $confirmer
     */
    public function confirmRecent(callable $confirmer): void
    {
        $this->confirmer = Closure::fromCallable($confirmer);
    }

    /**
     * Refuse an erasure request for a reason of the host's (an open dispute,
     * an unpaid invoice). Return the reason to show the member, an empty
     * string to refuse without one, or null to allow it.
     *
     * @param  callable(DataSubject): ?string  $blocker
     */
    public function blockErasure(callable $blocker): void
    {
        $this->blocker = Closure::fromCallable($blocker);
    }

    /**
     * Called once a request is recorded, with the subject and the request id:
     * lock the account during the grace period here if your app wants to.
     * `Events\ErasureRequested` is dispatched as well.
     *
     * @param  callable(DataSubject, string): void  $callback
     */
    public function onErasureRequested(callable $callback): void
    {
        $this->onErasure[] = Closure::fromCallable($callback);
    }

    /**
     * Add a registrant. Keys are unique; `fancy-compliance.` is this package's.
     */
    public function register(DataRegistrant $registrant): void
    {
        $this->add($registrant, false);
    }

    /** @internal the package's own registrants, registered before any other */
    public function registerFirstParty(DataRegistrant $registrant): void
    {
        $this->add($registrant, true);
    }

    /** @return list<DataRegistrant> in registration order: this package's first */
    public function registrants(): array
    {
        return array_values($this->registrants);
    }

    public function configured(): bool
    {
        return $this->subjects !== null;
    }

    public function subjectFor(Request $request): ?DataSubject
    {
        if ($this->subjects === null) {
            return null;
        }
        $subject = ($this->subjects)($request);
        if ($subject !== null && ! $subject instanceof DataSubject) {
            throw new UnexpectedValueException('The DataRights subject resolver must return a DataSubject or null.');
        }

        return $subject;
    }

    public function allows(DataSubject $actor, DataSubject $target, string $right): bool
    {
        if ($this->authorizer === null) {
            return $actor->equals($target);
        }

        return ($this->authorizer)($actor, $target, $right) === true;
    }

    /** Whether this request carries a recent enough re-authentication. Fails closed. */
    public function confirmsRecent(Request $request): bool
    {
        if ($this->confirmer !== null) {
            return ($this->confirmer)($request) === true;
        }
        if (! $request->hasSession()) {
            return false;
        }
        $at = $request->session()->get('auth.password_confirmed_at');
        if (! is_int($at) && ! (is_string($at) && ctype_digit($at))) {
            return false;
        }
        $age = Carbon::now()->getTimestamp() - (int) $at;

        return $age >= 0 && $age < $this->config()->reauthWindowMinutes() * 60;
    }

    /** @return array{0: bool, 1: string|null} blocked, and the reason to show */
    public function erasureBlock(DataSubject $subject): array
    {
        if ($this->blocker === null) {
            return [false, null];
        }
        $reason = ($this->blocker)($subject);
        if ($reason === null || $reason === false) {
            return [false, null];
        }
        $reason = is_string($reason) ? trim($reason) : '';

        return [true, $reason === '' ? null : mb_substr($reason, 0, 500)];
    }

    /** @internal */
    public function erasureRequested(DataSubject $subject, string $requestId): void
    {
        foreach ($this->onErasure as $callback) {
            $callback($subject, $requestId);
        }
    }

    public function keysFor(DataSubject $subject): SubjectKeys
    {
        if ($this->keys === null) {
            return SubjectKeys::defaultFor($subject)->forSubject($subject);
        }
        $keys = ($this->keys)($subject);
        if (! $keys instanceof SubjectKeys) {
            throw new UnexpectedValueException('The DataRights key resolver must return SubjectKeys.');
        }

        return $keys->forSubject($subject);
    }

    /**
     * The target's consent ledger rows, newest first. Authorisation is the
     * caller's: this reads, it does not decide who may.
     */
    public function consentHistory(DataSubject $target, int $limit = 50, ?int $before = null): ConsentHistoryPage
    {
        $id = $this->keysFor($target)->get(SubjectKeys::CONSENT_USER);
        if (! is_string($id) && ! is_int($id)) {
            return new ConsentHistoryPage([], null);
        }

        return $this->consent->historyFor(Subject::user($id), $limit, $before);
    }

    /** @return array<string, int> rows each registrant holds for the subject; changes nothing */
    public function holdings(DataSubject $subject): array
    {
        $keys = $this->keysFor($subject);
        $out = [];
        foreach ($this->registrants as $key => $registrant) {
            $out[$key] = $registrant->holds($keys);
        }

        return $out;
    }

    /**
     * Request an export for `$target`, as PHP. Authorisation and
     * re-authentication are the caller's; the limits are not.
     *
     * @return array<string, mixed> the protocol's ExportView
     */
    public function export(DataSubject $target, string $format = 'json'): array
    {
        $exports = $this->app->make(ExportService::class);

        return $exports->view($exports->request($target, $format), true);
    }

    /** @return array<string, mixed> the protocol's ErasureView */
    public function requestErasure(DataSubject $target): array
    {
        $erasure = $this->app->make(ErasureService::class);

        return $erasure->view($erasure->request($target));
    }

    /** @return array<string, mixed> the protocol's ErasureView */
    public function cancelErasure(DataSubject $target, string $id): array
    {
        $erasure = $this->app->make(ErasureService::class);

        return $erasure->view($erasure->cancel($target, $id));
    }

    /**
     * Run a request now, whatever its due time: for a host's admin path. A
     * cancelled or executed request is left as it is.
     *
     * @return array<string, mixed>|null the protocol's ErasureView, null for an unknown id
     */
    public function executeErasure(string $id): ?array
    {
        $erasure = $this->app->make(ErasureService::class);
        $request = $erasure->execute($id, force: true);

        return $request === null ? null : $erasure->view($request);
    }

    public function config(): DataRightsConfig
    {
        return new DataRightsConfig($this->app->make('config'));
    }

    private function add(DataRegistrant $registrant, bool $firstParty): void
    {
        $key = $registrant->key();
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidRegistrant("A data registrant key is 1 to 64 lowercase letters, digits, dots, dashes or underscores; got [{$key}].");
        }
        if (isset($this->registrants[$key])) {
            throw new InvalidRegistrant("A data registrant named [{$key}] is already registered.");
        }
        if (! $firstParty && str_starts_with($key, self::RESERVED_PREFIX)) {
            throw new InvalidRegistrant('The [fancy-compliance.] prefix is reserved for this package.');
        }
        $this->registrants[$key] = $registrant;
    }
}
