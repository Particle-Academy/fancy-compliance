# particle-academy/fancy-compliance

Consent for Laravel apps: host-defined categories, an append-only consent ledger
per user and per guest, withdrawal, re-consent when your policy changes, and a
server gate other packages check before they collect anything.

The UI is [`@particle-academy/fancy-compliance-ui`](https://github.com/Particle-Academy/fancy-compliance-ui):
a banner, a preferences dialog and script gating, built from react-fancy.

**Scope of this release: consent only.** GDPR data export and erasure are
planned and are not here yet.

- PHP `^8.4`, Laravel 13.
- No dependencies beyond the Laravel components it integrates with.
- Inertia is optional: the props are shared when `inertiajs/inertia-laravel` is installed.

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

An agent may read consent state (`Consent::allows`, `Consent::state`, the props). **An agent
must never record or withdraw consent on a person's behalf**: consent is the person's own
act, and a choice someone else made for them is not consent. The record routes are for the
person's own browser or app. This package ships no agent tool that writes, and any bridge
built on it must be read-only.

## What it does not do

- GDPR export and erasure (planned).
- Block scripts by itself: the server gate covers what your server does; the UI package
  covers scripts loaded through it. Scripts you inject outside both are not blocked.
- Rate limits, and geo rules (which jurisdictions see a banner): both are the host's.

## Testing

```bash
composer test     # Pest 5, hand-built Laravel kernel, in-memory SQLite
composer lint
```

## License

MIT
