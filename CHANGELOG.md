# Changelog

Notable changes to `particle-academy/fancy-compliance`.

**Pre-1.0: breaking changes land in MINOR releases.** Read those entries before upgrading.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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
