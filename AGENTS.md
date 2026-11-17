# Using particle-academy/fancy-compliance — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-compliance
php artisan migrate
php artisan vendor:publish --tag=fancy-compliance-config   # optional
php artisan vendor:publish --tag=fancy-compliance-lang     # optional, to translate
```

- PHP `^8.4`, Laravel 13. The service provider and the `Consent` facade are
  auto-discovered.
- Inertia is optional: the props are shared as `fancyCompliance` when
  `inertiajs/inertia-laravel` is installed.
- The UI is the npm package `@particle-academy/fancy-compliance-ui`.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package is the registry entry `fancy-compliance`;
find it with `search-backend-packages` (stack `php`). The UI half is
`fancy-compliance-ui` (plus `consent-banner`, `consent-gate`,
`consent-preferences`), found with `search-components`.

## API essentials

- Categories are the host's, in `config/fancy-compliance.php`. `necessary` is
  always required.
- Gate: `Consent::allows('analytics')`; middleware `consent:analytics` (403) or
  `consent:analytics,drop` (204); Blade `@consent('analytics') ... @endconsent`.
- Other packages type-hint `FancyCompliance\Contracts\ConsentGate`, a read-only
  one-method interface.
- `Consent::props()` is what the UI renders from; Blade pages print it with
  `@fancyComplianceProps`.
- Routes (in the host's `web` group): `GET|POST /fancy-compliance/consent`,
  `POST /fancy-compliance/consent/withdraw`.
- Data rights: export and staged deletion through the `DataRights` facade and
  registrants; see the README sections on data rights.

## Traps

- **An unknown category is `false`** (fail closed) and a 422 on input.
- **The guest cookie must be readable where you gate.** Outside the `web` group,
  add `fancy_compliance_subject` to EncryptCookies' exceptions, or every guest
  reads as undecided.
- **Inertia pages outside `web`** need `ShareInertiaProps::class` added to their
  group, or the prop is missing after the first request under Octane.
- **Rate limiting is the host's.** The routes add no throttle; add one in
  `routes.middleware`.
- **Erasure needs `FANCY_COMPLIANCE_ERASURE_KEY`** (32+ bytes); without it nothing
  is recorded (503).
- Re-authentication before an export or erasure request is on by default; without
  it the routes answer `423 {"error":"reauth_required"}`.

## Agents

An agent may read consent state (`Consent::allows`, `Consent::state`, the props). **An agent
must never record or withdraw consent on a person's behalf**: consent is the person's own
act, and a choice someone else made for them is not consent. The record routes are for the
person's own browser or app. This package ships no agent tool that writes, and any bridge
built on it must be read-only.

### Data rights

An agent may read export and erasure status. **An agent must never request an erasure or
download an export for a person**: the UI fences those controls, and nothing here offers an
agent tool that does either.
