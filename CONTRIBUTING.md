# Contributing to particle-academy/fancy-compliance

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Code map

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
- `Console\PruneCommand`: `fancy-compliance:prune`, an alias that runs only the
  `fancy-compliance.ledger` retention policy; a no-op while `retention.days` is null.
- `ConsentManager::history` / `historyFor`: `ConsentHistoryPage` of `ConsentHistoryEntry`,
  newest first, `nextBefore` cursor, capped at `data_rights.history.max_limit`.
- `DataRights\DataRightsManager` (facade `DataRights`): the host's `subjects`, `authorize`,
  `resolveFor`. `DataSubject` is `{type, id}` named by the host; `SubjectKeys::CONSENT_USER`
  (`user`) is the key the ledger reads.
- `Http\HistoryController`: `GET {prefix}/history`, `fancy-compliance.history`.
- `Retention\RetentionRegistry` (facade `Retention`), `CutoffPolicy`, `RetentionPolicy`,
  `RetentionOutcome`, `RetentionRunner`, `LedgerRetention` (registered first, in the
  singleton). `Console\RetentionCommand`: `fancy-compliance:retention --dry-run --only=*`.
- Data rights, phases 3 and 4 (contract: `docs/data-rights-protocol.md`):
  - `DataRights\DataRegistrant` (key, version, export, erase, holds); `DataRightsManager`
    holds the registry (`register`; `registerFirstParty` for this package's own, which own the
    `fancy-compliance.` prefix), `confirmRecent`, `blockErasure`, `onErasureRequested`,
    `holdings`. `SubjectKeys::subject()` is the subject the keys were resolved for.
  - `Registrants\ConsentLedgerRegistrant` (`fancy-compliance.consents`) and
    `ExportRecordsRegistrant` (`fancy-compliance.exports`), registered in the singleton, first.
  - `ExportService` + `ExportBundle` / `ExportWriter` / `ExportLimitExceeded`; `Jobs\BuildExport`.
    `Http\ExportController`: `{prefix}/exports[/{id}[/download]]`.
  - `ErasureService` + `ErasurePlan`; `Http\ErasureController`: `{prefix}/erasure[/{id}[/cancel]]`;
    `Console\ErasureDueCommand` (`fancy-compliance:erasure-due --dry-run --retry`).
  - `Http\DataRightsResponses`: the shared order (404 not configured, 401, 422, 403, 423) and
    error bodies. `DataRightsConfig` reads every setting with defaults; a present but invalid
    value throws (500), never "no limit". `Pseudonyms`: erasure HMAC, export subject hash, ids.
  - Models `DataExport`, `ErasureRequest` (mutable state), `ErasureRecord` (the append-only
    audit trail, `fancy_compliance_erasures`). `Retention\ExportRetention`.
- `ComplianceServiceProvider`: routes, middleware alias, `@consent` / `@fancyComplianceProps`,
  `Inertia::share('fancyCompliance', ...)` only when `Inertia\Inertia` exists, and the
  `Login` listener that links a guest's decision to the user.
- `Http\ShareInertiaProps`: added to `web`; re-shares the Inertia prop on every request.
  A boot-time share alone is lost under Octane, which flushes Inertia's shared props
  between requests. Added through the HTTP kernel (`appendMiddlewareToGroup`), not the
  router: the kernel's `syncMiddlewareToRouter()` replaces the router's groups and drops a
  router-only push. Router only when there is no kernel with a `web` group.

## Invariants a change must keep

- **Append-only.** Every decision, withdrawal and login link is a new row. The current state
  is the latest row per subject by id. Two sanctioned writes only, both through the query
  builder: `prune` deletes (never a subject's latest row), and an executed erasure re-keys
  (or, when the host chose `delete`, deletes) that subject's rows.
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
- **Data rights fail closed.** No subject resolver: the history route is a 404. The default
  authoriser allows only actor equals target, and it is asked for the subject's own history
  too. Nothing reads `users`, assumes `App\Models\User`, an `id` column or a Gate ability.
- **History output never carries the subject key or a guest hash** (`linked` is a bool).
- **A retention dry run never calls `prune`.** Invalid days fail the policy; they never mean
  "keep everything" or "delete everything". One failing policy does not stop the rest, and
  the command exits non-zero.
- **The ledger entry `ConsentHistoryEntry::toArray()` shape** is a contract with the UI's
  `ConsentHistoryEntry` type: change it only additively.
- **No unauthenticated path to an export or an erasure.** A download needs the authenticated
  subject AND a single-use token (stored as SHA-256); every read of a ready export issues a
  fresh one and a download spends it. Never add a signed-URL-only or emailed link.
- **Re-authentication before requesting an export or an erasure is on by default** and is
  checked after the authoriser. Status, download and cancel never ask for it.
- **No raw subject at rest in the data-rights tables.** Exports store a keyed hash and hold
  the subject encrypted only until built; erasure requests hold it encrypted only while they
  can still run (cleared on executed and cancelled); the audit trail only ever has the
  erasure pseudonym. Error details stay in the audit trail, never in a view.
- **Erasure without `FANCY_COMPLIANCE_ERASURE_KEY` (32+ bytes) records nothing (503).**
- **Each registrant erases in its own transaction**; a failure leaves the request
  `partially_executed`, and a resume skips registrants already `done`. Executed and
  cancelled requests never run again. Cancelling touches no data.
- **The consent ledger is re-keyed or deleted only by `ConsentLedgerRegistrant`, through the
  query builder.** The model guard stays; erasure and retention are the only sanctioned writes.
- **Limits are counted from the tables** (pending, per day), so they hold across cache flushes.
- Export and erasure view shapes are a contract with the UI's types: change them only
  additively, and change `docs/data-rights-protocol.md` in the same commit.

## Tests

Pest 5 on a hand-built kernel (`tests/Support/TestApp.php`, no testbench), in-memory SQLite.
`tests/Stubs/Inertia/Inertia.php` stands in for inertia-laravel's facade (autoload-dev only).
`php tests/lint.php` lints every PHP file.

```bash
composer test     # Pest 5, hand-built Laravel kernel, in-memory SQLite
composer lint
```
