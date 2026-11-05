# fancy-compliance code map

`FancyCompliance\` is a PHP 8.4 library for Laravel 13: consent categories, an
append-only consent ledger, and a server gate. Its UI is
`@particle-academy/fancy-compliance-ui`; the props below are the contract between them.

## Where things are

- `Categories` / `Category`: the host's categories from `fancy-compliance.categories`.
  `necessary` is added when missing and is ALWAYS required, whatever the config says.
  Keys match `^[a-z][a-z0-9_-]{0,31}$`, at most 32 categories.
- `ConsentManager` (singleton, also bound as `Contracts\ConsentGate`, facade `Consent`):
  `allows`, `state`, `record`, `withdraw`, `linkGuestToUser`, `props`, `propsScript`,
  `pendingGuestCookie`.
- `ConsentState`: the latest row read against the current policy version.
- `Models\ConsentRecord`: one ledger row. Updating or deleting through the model throws
  `LedgerIsAppendOnly`.
- `Http\ConsentController`: `GET|POST {prefix}/consent`, `POST {prefix}/consent/withdraw`,
  named `fancy-compliance.show|record|withdraw`, in the host's `web` group.
- `Http\RequireConsent`: middleware alias `consent:<category>[,drop]` (403, or 204 with `drop`).
- `Console\PruneCommand`: `fancy-compliance:prune`, a no-op while `retention.days` is null.
- `ComplianceServiceProvider`: routes, middleware alias, `@consent` / `@fancyComplianceProps`,
  `Inertia::share('fancyCompliance', ...)` only when `Inertia\Inertia` exists, and the
  `Login` listener that links a guest's decision to the user.

## Invariants a change must keep

- **Append-only.** Every decision, withdrawal and login link is a new row. The current state
  is the latest row per subject by id. Nothing updates a row; only `prune` deletes, never a
  subject's latest row.
- **`necessary` can never be refused**, not by config, input or withdrawal.
- **No IP address, no user agent, no fingerprint** in the ledger, raw or hashed. A test scans
  every column value for both.
- **A guest is a random 43-character token** in an HttpOnly cookie, set only after the first
  decision. The ledger stores its SHA-256. Anything not matching the token shape is ignored.
- **Unknown categories fail closed** in `allows`, and are a 422 on input.
- **Input is bounded before the manager**: lists of at most 32 strings of at most 32 chars.
- **A new `policy_version` makes every earlier decision non-current**: only required
  categories are allowed until the person decides again.
- The `props()` shape is a cross-package contract with the UI: change it only additively.

## Tests

Pest 5 on a hand-built kernel (`tests/Support/TestApp.php`, no testbench), in-memory SQLite.
`tests/Stubs/Inertia/Inertia.php` stands in for inertia-laravel's facade (autoload-dev only).
`php tests/lint.php` lints every PHP file.
