# Data rights protocol (version 1)

The HTTP contract between `particle-academy/fancy-compliance` and any client,
including `@particle-academy/fancy-compliance-ui`. It is written once, here, so a
backend in another language can serve the same UI. Council change CC-fancy-0038.

These are mechanisms that can support a host's obligations. They promise no
compliance with any law. Have it reviewed by a lawyer before relying on it.

## Common rules

- Every route sits under the host's prefix (default `/fancy-compliance`) and its
  `routes.middleware` (default `web`), so the host's session, CSRF and throttles
  apply. POSTs need the host's CSRF token.
- Every response is JSON with `Cache-Control: no-store, private`, except a
  download, which is the file (also `no-store`).
- An error is `{"error": "<code>"}` with the status below, plus the fields named
  for that code.
- Times are ISO 8601 UTC, `2026-11-15T09:30:00Z`.
- A request may name a target other than the caller with `subject_type` and
  `subject_id` (query for GET, body for POST). The host's authoriser decides; by
  default only a subject acting for itself is allowed.

| Status | `error` | Means |
|---|---|---|
| 404 | `data_rights_not_configured` | The host registered no subject resolver. Nothing is served. |
| 404 | `not_found` | No such export or erasure for this subject (also: another subject's id, a used or wrong download token). |
| 401 | `unauthenticated` | No subject. There is no unauthenticated path to an export or an erasure. |
| 403 | `forbidden` | The authoriser refused this actor, target and right. |
| 423 | `reauth_required` | The host requires a recent re-authentication (on by default) and the caller has none. |
| 422 | `invalid_input` | Malformed input. |
| 429 | `rate_limited` | Daily limit reached. `retryAfter` (seconds) is included. |
| 409 | `export_in_progress` | An export for this subject is still pending. `export` is included. |
| 409 | `erasure_pending` | An erasure request is already pending. `erasure` is included. |
| 409 | `erasure_blocked` | The host blocked the request. `reason` (host text, may be null) is included. |
| 409 | `not_cancellable` | The erasure is no longer pending. `erasure` is included. |
| 410 | `expired` | The export is past its time to live; the file is gone. |
| 503 | `erasure_not_configured` | No erasure key is set (`FANCY_COMPLIANCE_ERASURE_KEY`). Nothing is recorded. |

## Export

### `GET {prefix}/exports`

```json
{
  "exports": [ExportView],
  "formats": ["json", "zip"],
  "limits": { "perDay": 3, "remainingToday": 2 }
}
```

The subject's exports, newest first, at most 10. `formats` lists what this host
can build: `zip` only when `ext-zip` is loaded and the host allows it.

### `POST {prefix}/exports`

Body `{"format": "json" | "zip"}` (default `json`). Needs a recent
re-authentication when the host requires one. `202 {"export": ExportView}`.

### `GET {prefix}/exports/{id}`

`200 {"export": ExportView}`.

### `GET {prefix}/exports/{id}/download?token=<token>`

The file, as an attachment. It needs BOTH the authenticated subject the export
belongs to (or an actor the authoriser allows for `export`) AND the single-use
token from that export's `download.url`. A token works once; reading the export
again (`GET exports` or `GET exports/{id}`) issues a fresh one. There is no link
that works without signing in.

### `ExportView`

```json
{
  "id": "01JD3...",
  "format": "json",
  "status": "pending" | "ready" | "failed" | "downloaded" | "expired",
  "requestedAt": "2026-11-15T09:30:00Z",
  "readyAt": "2026-11-15T09:30:02Z" | null,
  "expiresAt": "2026-11-16T09:30:02Z" | null,
  "bytes": 18234 | null,
  "error": null | "too_large" | "too_many_files" | "registrant_failed" | "zip_unavailable" | "build_failed",
  "download": { "url": "/fancy-compliance/exports/01JD3.../download?token=..." } | null
}
```

`download` is present only while `status` is `ready`. `downloaded` means the
host deletes an export after its first download and this one has been taken.

### The export file

**JSON** (`application/json`):

```json
{
  "format": "fancy-compliance.export",
  "version": 1,
  "subject": { "type": "user", "id": "42" },
  "generated_at": "2026-11-15T09:30:02Z",
  "sections": { "<registrant>": { "<section>": "<any JSON>" } },
  "files_omitted": [{ "registrant": "<registrant>", "name": "<name>", "bytes": 1234 }]
}
```

Attached files (for example a sealed PDF) cannot travel in JSON; they are named
in `files_omitted`, and the ZIP format carries them.

**ZIP** (`application/zip`): `manifest.json`, one `<registrant>/<section>.json`
per section, and attached files under `<registrant>/files/<name>`. The manifest:

```json
{
  "format": "fancy-compliance.export",
  "version": 1,
  "subject": { "type": "user", "id": "42" },
  "generated_at": "2026-11-15T09:30:02Z",
  "registrants": ["fancy-compliance.consents"],
  "files": [{ "path": "fancy-compliance.consents/consents.json", "sha256": "...", "bytes": 812, "rows": 3 }]
}
```

Not exported, by any registrant: secrets (key material, OTP hashes, session or
download tokens, guest cookie hashes) and other people's personal data.

## Erasure

### `GET {prefix}/erasure`

`200 {"erasure": ErasureView | null, "graceDays": 30}`: the subject's latest
request.

### `POST {prefix}/erasure`

Needs a recent re-authentication when the host requires one.
`202 {"erasure": ErasureView}`. With a grace period of 0 the request executes at
once and the view is already `executed` (or `partially_executed`).

### `GET {prefix}/erasure/{id}`

`200 {"erasure": ErasureView}`.

### `POST {prefix}/erasure/{id}/cancel`

Only while `pending`. `200 {"erasure": ErasureView}`, now `cancelled`. Cancelling
touches no data.

### `ErasureView`

```json
{
  "id": "01JD3...",
  "status": "pending" | "cancelled" | "executing" | "executed" | "partially_executed",
  "requestedAt": "2026-11-15T09:30:00Z",
  "dueAt": "2026-12-15T09:30:00Z",
  "cancelledAt": null,
  "executedAt": null,
  "registrants": [
    {
      "key": "fancy-compliance.consents",
      "status": "waiting" | "done" | "failed",
      "deleted": { "<table>": 3 },
      "anonymised": { "<table>": 1 },
      "retained": [{ "table": "fancy_compliance_consents", "rows": 4, "reason": "...", "rekeyed": true }]
    }
  ]
}
```

`retained` lists what was kept and why: `rekeyed: true` means the rows stay with
the subject replaced by a keyed-hash pseudonym; `false` means they are kept
unchanged (for example a hash-chained audit log, which cannot be rewritten
without breaking its verification). A failure's detail is in the host's erasure
audit trail, not in this view.
