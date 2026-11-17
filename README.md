# particle-academy/fancy-compliance

Consent for Laravel apps: host-defined categories, an append-only consent ledger
per user and per guest, withdrawal, re-consent when your policy changes, and a
server gate other packages check before they collect anything.

The UI is [`@particle-academy/fancy-compliance-ui`](https://github.com/Particle-Academy/fancy-compliance-ui):
a banner, a preferences dialog and script gating, built from react-fancy.

**Scope: consent, consent history, retention, self-service data export and
staged deletion.** These are mechanisms that can support your obligations, not
a promise that you meet them: that depends on your jurisdiction, your data and
your processes. Have it reviewed by a lawyer before relying on it.

- PHP `^8.4`, Laravel 13.
- No dependencies beyond the Laravel components it integrates with.
- Inertia is optional: the props are shared when `inertiajs/inertia-laravel` is installed.

<!-- fancy-agents-note:start -->
> **For agents.** This package is listed on the Fancy MCP catalog server at
> `https://ui.particle.academy/mcp` (streamable HTTP, no API key; MCP id `fancy-ui`),
> with install commands and docs, alongside the rest of the kit. How an agent
> installs and uses this package is in [AGENTS.md](https://github.com/Particle-Academy/fancy-compliance/blob/main/AGENTS.md).
<!-- fancy-agents-note:end -->

## Install

```bash
composer require particle-academy/fancy-compliance
php artisan migrate
php artisan vendor:publish --tag=fancy-compliance-config   # optional
php artisan vendor:publish --tag=fancy-compliance-lang     # optional, to translate
```

The service provider and the `Consent` facade are auto-discovered.

## Categories

Categories are yours, in `config/fancy-compliance.php`:

```php
'categories' => [
    'necessary' => ['label' => 'fancy-compliance::categories.necessary.label', 'description' => '...', 'purposes' => ['...']],
    'analytics' => ['label' => 'consent.analytics.label', 'description' => 'consent.analytics.description', 'purposes' => ['consent.analytics.usage']],
],
```

- A key is a short lowercase identifier (`^[a-z][a-z0-9_-]{0,31}$`), at most 32 categories.
- `label`, `description` and every purpose are **translation keys**. The server resolves
  them for the current locale and sends both the key and the text; the UI uses your
  messages first, then the server's text, then its own English.
- **`necessary` always exists and can never be refused.** It is added if you leave it out,
  and a config that marks it optional is overruled. Keep it to what the site cannot work
  without, which includes the consent cookie itself.

## The ledger

One row per decision in `fancy_compliance_consents`:

| column | what |
|---|---|
| `subject_type`, `subject_id` | `user` + the auth identifier, or `guest` + the SHA-256 of the guest token |
| `action` | `decision`, `withdrawal`, or `link` (a guest's decision carried over at login) |
| `granted`, `refused` | JSON lists of category keys; every optional category is in one of the two |
| `policy_version` | the version the person was shown |
| `source` | `banner`, `preferences`, `api`, or `login` (for `link` rows) |
| `linked_from` | for `link` rows, the guest subject the decision came from |
| `created_at` | when |

- **Append-only.** A withdrawal is a new row, never an edit. The model refuses `save()`
  on an existing row and `delete()`.
- **Current state is the latest row per subject.**
- **No IP address and no user agent are stored**, raw or hashed. Neither is needed to show
  what someone chose, and both are personal data.
- **Guests** get a random token in a first-party, HttpOnly cookie
  (`fancy_compliance_subject`), set only once they decide. The cookie exists to remember
  the decision, including a refusal, so it is strictly necessary and needs no consent of
  its own. The ledger stores only the token's hash.
- **On login**, a guest's decision is appended to the user as a `link` row, unless the user
  already has a newer decision of their own. The guest rows are left as they are.
- **A new policy version requires re-consent.** Change `policy_version` (or
  `FANCY_COMPLIANCE_POLICY_VERSION`) and every earlier decision stops being current: the
  banner shows again and only `necessary` is allowed until the person decides.

### Retention: kept by default, on purpose

`retention.days` is `null`, which keeps every row. A consent record is your evidence that
you had consent, and of what the person was told; delete it and you can no longer show
either. If your counsel sets a period, set the days and schedule the command:

```php
Schedule::command('fancy-compliance:prune')->daily();
```

Pruning never deletes a subject's latest row, because that row is their current state.

## The server gate

```php
use FancyCompliance\Facades\Consent;

if (Consent::allows('analytics')) {
    // collect
}
```

- `Consent::allows($category, $request = null)`: `necessary` is always true; an unknown
  category is **false** (fail closed); anything else needs a current decision granting it.
- Middleware: `consent:analytics` answers `403 {"error":"consent_required"}` until consent;
  `consent:analytics,drop` answers `204` instead, for beacons and batches.
- Blade: `@consent('analytics') <script src="..."></script> @endconsent`.
- Other packages should type-hint `FancyCompliance\Contracts\ConsentGate`, a read-only
  interface with one method, so they can bind their own fallback when this package is not
  installed.

**The guest cookie must be readable where you gate.** In the `web` group, EncryptCookies
decrypts it. On a route outside that group, add `fancy_compliance_subject` to
EncryptCookies' exceptions, or every guest reads as undecided and is refused. That fails
closed, but collects nothing.

## For the UI

`Consent::props()` is what the UI renders from:

```json
{
  "policyVersion": "1",
  "categories": [{ "key": "analytics", "label": "...", "labelText": "Analytics", "description": "...", "descriptionText": "...", "purposes": [{ "key": "...", "text": "..." }], "required": false }],
  "consent": { "granted": ["necessary", "analytics"], "refused": ["marketing"], "policyVersion": "1", "decidedAt": "2026-11-05T12:00:00Z", "source": "banner", "current": true },
  "needsConsent": false,
  "routes": { "show": "/fancy-compliance/consent", "record": "/fancy-compliance/consent", "withdraw": "/fancy-compliance/consent/withdraw" }
}
```

- **Inertia:** shared as `fancyCompliance` (`inertia.key`, or `inertia.share => false`).
  It is shared on every request by `FancyCompliance\Http\ShareInertiaProps`, which the
  package pushes onto your `web` group.
  - **Octane and other long-lived processes** (an in-process Pest browser server, say)
    serve many requests from one booted app and flush Inertia's shared props between them,
    so a share made once at boot is gone from the second request. The middleware is what
    keeps the prop there. If your Inertia pages are served from a group other than `web`,
    add `ShareInertiaProps::class` to that group yourself.
  - The package adds it through the HTTP kernel, so it survives the kernel re-syncing its
    groups onto the router. To declare it yourself instead (Laravel 11+, `bootstrap/app.php`):

    ```php
    ->withMiddleware(fn ($m) => $m->web(append: [\FancyCompliance\Http\ShareInertiaProps::class]))
    ```

    Declaring it as well is harmless: the kernel keeps one entry, and a second run in the same
    request only re-shares the same closure.
- **Blade pages:** `@fancyComplianceProps` prints it as
  `<script type="application/json" id="fancy-compliance-props">`, with every `<`, `>`, `&`
  and quote escaped.
- **Routes**, in your `web` group so your CSRF protection applies:
  - `GET  /fancy-compliance/consent`: the props.
  - `POST /fancy-compliance/consent` `{"granted": ["analytics"], "source": "banner"}`: a
    decision. Every optional category not in `granted` is recorded as refused.
  - `POST /fancy-compliance/consent/withdraw` `{"categories": ["analytics"], "source": "preferences"}`:
    a withdrawal (all optional categories when `categories` is absent).
  - Bad input is a `422 {"error": "<code>"}` and nothing is written: `invalid_input`
    (not a list, more than 32 entries, an entry over 32 characters), `unknown_category`,
    `invalid_source`, `necessary_not_refusable`.

**Rate limiting is yours.** The routes add no throttle, because the right limit depends on
your traffic. Add one in `routes.middleware`, for example `['web', 'throttle:30,1']`.

## Agents

Agents may read consent state but never record or withdraw consent for a person; this
package ships no agent tool that writes. The rule for agents is in [AGENTS.md](./AGENTS.md).

## Consent history

A person can see every decision they made: `GET /fancy-compliance/history` (route name
`fancy-compliance.history`), or `Consent::history()` in PHP. The UI package's
`ConsentHistory` renders it.

**Nothing is served until you say who is asking.** Without a subject resolver the route
answers `404 {"error":"data_rights_not_configured"}`. Register one in a service provider;
nothing here assumes a `users` table, an `id` column or a Gate ability:

```php
use FancyCompliance\DataRights\DataSubject;
use FancyCompliance\DataRights\SubjectKeys;
use FancyCompliance\Facades\DataRights;

// Who is asking. Return null when nobody is signed in.
DataRights::subjects(fn (Request $r) => $r->user()
    ? DataSubject::of('user', $r->user()->getAuthIdentifier())
    : null);

// Optional. Who may read whose history. The default allows only a subject acting
// for itself, so support staff get access only when you write this.
DataRights::authorize(fn (DataSubject $actor, DataSubject $target, string $right): bool =>
    $actor->equals($target) || ($right === 'history' && Staff::canSupport($actor)));

// Optional. Where a subject's data lives. The ledger reads the `user` key: the auth
// identifier its rows were recorded under. Without this, a subject's keys are
// [type => id], so a `user` subject reads its own rows.
DataRights::resolveFor(fn (DataSubject $s) => SubjectKeys::of(['user' => Member::find($s->id)?->auth_id]));
```

- **Own history:** `GET /fancy-compliance/history`. The authoriser is asked even then, with
  the right `history`.
- **Someone else's:** `?subject_type=user&subject_id=42`, allowed only when your authoriser
  says so; otherwise `403`.
- **Guests** read the rows of their own consent cookie and cannot name anyone else
  (`data_rights.history.guests`, on by default). No subject and no cookie is `401`.
- **Paging:** `?limit=` (default `data_rights.history.limit`, 50, capped at `max_limit`, 100)
  and `?before=<id>`. The response is `{"entries": [...], "nextBefore": 12}`; pass
  `nextBefore` as `before` for the next, older page. It is `null` on the last page.
- **Each entry:** `id`, `action`, `granted`, `refused`, `policyVersion`, `source`, `at`
  (ISO 8601 UTC), and `linked` for a guest decision carried over at login. **The subject key
  and the guest hash are never in the output.**
- Malformed `limit`, `before` or subject input is a `422`. Responses are `Cache-Control:
  no-store`. The route sits in `routes.middleware`: add your throttle there.

## Retention

One registry of retention policies and one command that runs them all:

```php
Schedule::command('fancy-compliance:retention')->daily();
```

- `--dry-run` counts and deletes nothing; `--only=<key>` (repeatable) runs some of them. An
  unknown key fails and runs nothing.
- A failing policy is reported, the others still run, and the command exits non-zero.
- **The consent ledger is the policy `fancy-compliance.ledger`**, off while
  `retention.days` is null (the default). `fancy-compliance:prune` still works: it runs that
  one policy and nothing else, exactly as before.
- `FancyCompliance\Events\RetentionPruned` (key, rows deleted per class) is dispatched after
  each real run, never after a dry run.

Register your own tables, in a service provider:

```php
use FancyCompliance\Facades\Retention;

Retention::policy('app.login-attempts',
    days: fn () => config('app.login_attempts_days'),   // read when it runs; null keeps everything
    prune: fn (CarbonImmutable $before): int => LoginAttempt::where('created_at', '<', $before)->delete(),
    count: fn (CarbonImmutable $before): int => LoginAttempt::where('created_at', '<', $before)->count(),
);
```

Days must be a whole number, 1 or more, or null; anything else fails the run rather than
keeping or deleting everything. A policy without `count` is skipped on a dry run, so a dry run
can never delete. A package with several windows of its own implements
`FancyCompliance\Retention\RetentionPolicy` and calls `Retention::register()`.

**These are mechanisms, not legal advice.** They can support your obligations; whether you
meet them depends on your jurisdiction, your data and your processes. Choosing a retention
period is a legal decision. Have it reviewed by a lawyer before relying on it.

## Data rights: export and staged deletion

A signed-in person can download their data and ask for it to be erased. The HTTP contract
is [`docs/data-rights-protocol.md`](docs/data-rights-protocol.md), and the UI package's
`DataExportPanel` and `AccountDeletionPanel` render it. Like history, **nothing is served
until you register a subject resolver** (see [Consent history](#consent-history)); the same
authoriser is asked, with the right `export` or `erase`.

### Where the data is: registrants

Each package that holds personal data registers a `DataRegistrant`: what to export, and how
to erase it. This package registers two of its own, first: `fancy-compliance.consents` (the
ledger) and `fancy-compliance.exports` (export records). Register your own tables the same
way, in a service provider:

```php
use FancyCompliance\DataRights\{DataRegistrant, ErasurePlan, ExportWriter, SubjectKeys};
use FancyCompliance\Facades\DataRights;

DataRights::register(new class implements DataRegistrant {
    public function key(): string { return 'app.orders'; }
    public function version(): string { return '1'; }
    public function export(SubjectKeys $k, ExportWriter $w): void {
        $w->json('orders', Order::where('customer_id', $k->get('user'))->get()->toArray());
        $w->file('invoice-17.pdf', Storage::get('invoices/17.pdf'));   // ZIP only
    }
    public function erase(SubjectKeys $k, ErasurePlan $p): void {
        $p->delete('carts', Cart::where('customer_id', $k->get('user'))->delete());
        $p->anonymise('reviews', Review::where('customer_id', $k->get('user'))->update(['customer_id' => null]));
        $p->retain('orders', Order::where('customer_id', $k->get('user'))->count(), 'Kept for tax records.');
    }
    public function holds(SubjectKeys $k): int { return Order::where('customer_id', $k->get('user'))->count(); }
});
```

- Read identifiers from `SubjectKeys` (your `DataRights::resolveFor`); never derive one from
  another. Never export secrets (key material, token or OTP hashes) or other people's data.
- `erase()` runs in a transaction on the default connection and must be idempotent. Say what
  you did on the plan; a `retain()` needs a reason, which the person sees. Pass
  `rekeyed: true` when the rows stay but the person was replaced by `$p->pseudonym()`.
- A hash-chained log cannot be rewritten without breaking its verification: `retain()` it,
  unchanged, with that reason.
- A package registers only when this one is installed:
  `if (class_exists(DataRegistrant::class)) { ... }`.

### Export

- `POST /fancy-compliance/exports` with `{"format": "json"}` or `"zip"` (ZIP only with
  `ext-zip`). The response is the export's status, including a `download.url` once it is ready.
- **A download needs the signed-in person AND the single-use token in that URL.** Each read
  of the export issues a fresh token. There is no link that works without signing in, so
  when you tell someone their export is ready (`Events\ExportReady`), tell them to sign in.
- Files are encrypted with your app key on `data_rights.exports.disk` (default `local`; keep
  it private) and deleted after `ttl_hours` (24), or after the first download with
  `delete_after_download`. The `fancy-compliance.exports` retention policy deletes expired
  files and records older than `record_days` (30).
- Limits, all settings: one pending export per person, `per_day` (3), `max_bytes` (50 MB),
  `max_files` (1000). A registrant that throws, or an export past a cap, fails the export
  with an `error` code; nothing half-built is kept.
- Set `exports.queue.enabled` to build on your queue (`Jobs\BuildExport`, which carries
  only the export id).
- Events: `ExportRequested`, `ExportReady`, `ExportFailed`, `ExportDownloaded`.

### Staged deletion

- `POST /fancy-compliance/erasure` records a request that waits out `erasure.grace_days`
  (30; **0 runs it at once**). `POST /fancy-compliance/erasure/{id}/cancel` cancels it until
  then, touching no data.
- **Set `FANCY_COMPLIANCE_ERASURE_KEY`** (32 bytes or more, `base64:` accepted). The
  person is named in the erasure tables only by an HMAC of them with this key. Without it,
  erasure answers `503` and records nothing. Keep it stable.
- Schedule the executor: `Schedule::command('fancy-compliance:erasure-due')->hourly();`
  Each registrant runs in its own transaction. If one fails, the request is
  `partially_executed`, the failure is in the audit trail, the command exits non-zero, and
  `--retry` runs only the ones that failed. `--dry-run` lists what is due and what each
  registrant holds.
- **The consent ledger** (`erasure.consents`): by default its rows are kept as evidence of
  consent, re-keyed to the pseudonym (`subject_type` `erased`), along with the guest rows a
  login linked; `delete` removes them instead. Choosing is a legal decision.
- `fancy_compliance_erasures` is the audit trail: append-only, one row per step (requested,
  cancelled, executing, each registrant with its version and counts or its failure,
  executed), naming the person only by pseudonym. `fancy_compliance_erasure_requests` holds
  each request's state; the subject is stored encrypted there only while the request can
  still run.
- Your hooks: `DataRights::blockErasure(fn (DataSubject $s) => Dispute::openFor($s->id)
  ? 'Your account has an open dispute.' : null)` refuses with a reason the member sees;
  `DataRights::onErasureRequested(fn (DataSubject $s, string $id) => ...)` is where you lock
  the account during the grace period, if you want to (the package does not assume it).
- Limits: one pending request per person, `erasure.per_day` (3).
- Events: `ErasureRequested`, `ErasureCancelled`, `ErasureExecuted`.

### Re-authentication

Requesting an export or an erasure needs a recent re-authentication. **On by default**
(`data_rights.reauth.required`); turning it off is your call. Without your own check it reads
`auth.password_confirmed_at` from the session, which Laravel's `password.confirm` middleware
writes, against `reauth.window_minutes` (10). Supply your own, a passkey step-up for
example, with `DataRights::confirmRecent(fn (Request $r): bool => ...)`. Without one the
routes answer `423 {"error":"reauth_required"}`; reading status, downloading and cancelling
do not ask.

### Agents

Agents may read export and erasure status but never request an erasure or download an
export for a person (see [AGENTS.md](./AGENTS.md)).

**These are mechanisms, not legal advice.** Whether an export is complete, how long a grace
period should be, and what must be kept are legal questions that depend on your
jurisdiction, your data and your processes. Have it reviewed by a lawyer before relying on it.

## What it does not do

- Exporters and anonymisers for other packages' data: each package ships its own, in its
  own release. Until it does, register one for its tables yourself.
- An export or erasure for a guest: both need a signed-in subject.
- Block scripts by itself: the server gate covers what your server does; the UI package
  covers scripts loaded through it. Scripts you inject outside both are not blocked.
- Rate limits, and geo rules (which jurisdictions see a banner): both are the host's.

## Testing

`composer test` runs the Pest suite; see [CONTRIBUTING.md](./CONTRIBUTING.md).

## License

MIT
