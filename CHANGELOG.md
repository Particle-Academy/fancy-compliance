# Changelog

Notable changes to `particle-academy/fancy-compliance`.

**Pre-1.0: breaking changes land in MINOR releases.** Read those entries before upgrading.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

Council change **CC-fancy-0038**, phases 1 and 2 (consent history, retention), approved by
the owner. These are mechanisms that can support a host's obligations; they promise no
compliance with any law. Have it reviewed by a lawyer before relying on it.

### Added

- **Consent history.** `Consent::history($request = null, $limit = null, $before = null)`
  returns a `ConsentHistoryPage` of `ConsentHistoryEntry` (id, action, granted, refused,
  policy version, source, time, and `linked` for a login link), newest first, with a
  `nextBefore` cursor. `Consent::historyFor(Subject $subject, ...)` reads any ledger subject.
  The subject key and the guest hash are never in an entry.
- **`GET {prefix}/history`** (`fancy-compliance.history`): the same, over HTTP, through the
  host's resolvers. **It answers 404 until the host registers a subject resolver**, so
  installing this release serves nothing new by itself. `401` with no subject and no guest
  cookie, `403` when the authoriser refuses, `422` for malformed `limit`, `before`,
  `subject_type` or `subject_id`; responses are `Cache-Control: no-store`.
- **`DataRights` facade** (`DataRights\DataRightsManager`): `subjects()` (who is asking),
  `authorize()` (actor, target, right; the default allows only actor equals target, so a
  staff path exists only when the host writes one) and `resolveFor()` (a subject's
  `SubjectKeys`). `DataSubject` is a host-named type and id, never assumed to be a `users`
  row. Guests read their own cookie's rows (`data_rights.history.guests`).
- Config `data_rights.history`: `enabled` (true), `guests` (true), `limit` (50),
  `max_limit` (100).
- **Retention registry.** `Retention::policy($key, days:, prune:, count:)` registers a
  one-window policy; `Retention::register(RetentionPolicy)` takes a package's own
  multi-window policy. `days` is read when the policy runs; anything other than a whole
  number of days, 1 or more, or null fails that policy rather than keeping or deleting
  everything.
- **`fancy-compliance:retention`** runs every policy: `--dry-run` (calls `count`, never
  `prune`; a policy without `count` is skipped), `--only=<key>`. A failing policy is reported,
  the others still run, and the exit code is non-zero. An unknown `--only` key runs nothing.
- `Events\RetentionPruned` (key, rows deleted per class), after every real run.

### Changed

- **The consent ledger's prune is now the registered policy `fancy-compliance.ledger`.**
  `fancy-compliance:prune` stays as an alias that runs only that policy, and gains
  `--dry-run`. **Consumer action:** none. A host that scheduled `fancy-compliance:prune`
  keeps exactly the same behaviour; schedule `fancy-compliance:retention` instead once other
  packages register policies.

## [0.1.2] - 2026-11-07

### Fixed

- **The Inertia prop went missing again under Pest's in-process browser server.** 0.1.1
  pushed `ShareInertiaProps` onto the router's `web` group. In a Laravel 11+ app the HTTP
  kernel owns the groups, and its `syncMiddlewareToRouter()` rewrites the router's groups
  from its own, so the next sync dropped the middleware and the prop was absent from the
  second request on. The provider now adds it with the kernel's `appendMiddlewareToGroup()`
  when the kernel has a `web` group, and falls back to the router otherwise. The kernel
  skips a middleware the group already has, so a host that also declares it gets one entry.
  **Consumer action:** none. If you worked around 0.1.1 by declaring `ShareInertiaProps` in
  `bootstrap/app.php`, you can keep it or remove it.

## [0.1.1] - 2026-11-07

### Fixed

- **The Inertia prop is shared on every request, so Octane keeps it.** The provider shared
  `fancyCompliance` once, in `boot()`. Inertia's `ResponseFactory` is a singleton, and
  long-lived servers (Octane, an in-process Pest browser server) flush its shared props
  between requests, so from the second request the client got no prop and
  `useConsentController` threw on `undefined.routes`. A new middleware,
  `FancyCompliance\Http\ShareInertiaProps`, now shares it per request and is pushed onto
  the `web` group. The boot-time share stays for routes outside `web` on a classic server.
  **Nothing to do** unless your Inertia pages live outside the `web` group and you run
  Octane: then add `ShareInertiaProps::class` to that group. `inertia.share` and
  `inertia.key` work as before.

## [0.1.0] - 2026-11-05

### Added

- **Consent categories from config.** Keys, label and description translation keys, and
  purposes. `necessary` is always present and always required, whatever the config says.
  English text ships for the defaults (`necessary`, `analytics`, `marketing`).
- **An append-only consent ledger** (`fancy_compliance_consents`): one row per decision,
  withdrawal or login link, with the subject (user id, or the SHA-256 of a random guest
  token), granted and refused categories, policy version, source and time. The model
  refuses updates and deletes. No IP address or user agent is stored.
- **Guests** are identified by a random token in an HttpOnly first-party cookie, set only
  after their first decision. On login, a guest's decision is appended to the user unless
  the user has a newer one.
- **Re-consent on a new `policy_version`.**
- **The server gate:** `Consent::allows()`, the `ConsentGate` contract, the
  `consent:<category>[,drop]` middleware and the `@consent` Blade block. Unknown
  categories fail closed.
- **Props for the UI**, shared with Inertia as `fancyCompliance` when Inertia is installed,
  printed by `@fancyComplianceProps` on Blade pages, and served by
  `GET /fancy-compliance/consent`. `POST /fancy-compliance/consent` and
  `POST /fancy-compliance/consent/withdraw` record and withdraw, in the host's `web` group.
  Input is bounded and validated against the configured categories.
- **`fancy-compliance:prune`**, off by default: ledger rows are kept because they are the
  evidence of consent. Never removes a subject's latest row.
- Tests: Pest 5 on a hand-built Laravel 13 kernel, no testbench. `laravel/framework` is a
  dev dependency under the estate's carve-out for the framework a package integrates with.

**Consumer action:** new package. Install, migrate, and add a throttle to
`fancy-compliance.routes.middleware`; rate limiting is the host's.
