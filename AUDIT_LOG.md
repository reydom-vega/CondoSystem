# Audit Log enhancement

Schema release `2026.10.15.2` upgrades the existing `superadmin/auditlog.php` and `audit_logs` ledger. No second audit page, activity ledger or export database table was created.

## Access and workflow

Global access remains **Super Admin only**, matching the existing `audit.read` policy. Admin, Security, Maintenance, Treasurer, Resident and Tenant accounts cannot access the global page, list, details, preview or downloads. Every request verifies the authenticated database account, current role, active/verified/approved state and session version. POST APIs and exports require the existing CSRF token.

The page opens on today's date in **Asia/Manila**. Daily, Monthly and Custom Range controls refresh the selected period; custom end dates include the complete final day. Search is debounced and combines with role, module, actual action type, recorded outcome and timestamp ordering. Apply Filters updates all controls together. Clear Filters restores today's unfiltered report.

The server returns 25 records per page and complete filtered summaries. Search supports recorded actor name, linked/snapshotted staff identifier, user ID, role, module, action, AUD record ID and safe target reference. It never searches confidential raw metadata. Older roles/names are taken from recorded values, rather than replacing them with a renamed account's current identity. A legacy staff identifier may use the current linked staff ID where no identifier snapshot exists; that fallback does not alter the original event.

## Outcomes and privacy

Historical entries do not contain trustworthy structured outcomes. They remain **Not recorded**, with zero inferred successes or failures. Rejecting a request is a completed decision, not evidence of a technical failure. Summary success/failure counts use only explicit outcomes; Refused is separately filterable. Unique actors count distinct users with activity in the selected period, excluding System.

New logging supports nullable actor name/identifier/role snapshots and an explicitly supplied `success`, `failed` or `refused` outcome. Callers without a known outcome retain NULL. Login/logout, QR events, payment confirmations, parking entry/checkout and parking invoice creation now record explicit outcomes at their existing action points. Verified gateway confirmations and automatic extension expiry identify System rather than the browsing user. Safe gateway references are retained in the private original audit note. Existing module audit calls remain in use.

The display/export projection does **not select original free-text details, IP addresses, payment credentials, passwords, QR content or arbitrary JSON**. It provides the actual recorded action, mapped module, authorized actor and safe target reference. Unknown/restricted entities and community-report targets have their action/reference withheld. Details explain this policy. Structured before/after changes do not exist in the current ledger and are not reconstructed. Historical records have no edit/delete endpoint and are not backfilled or rewritten by this upgrade. Privileged database administration remains outside the application interface's append-only guarantee.

QR verification appends one safe central event inside its existing deduplicated transaction; it stores the scan record reference, never its bearer URL. Job Reports continue using their own authorized activity catalog and existing QR source, so the new central QR event is not counted twice. Visitor parking invoice creation also records a safe bill event. Existing staff, maintenance, booking, violation, unit, registration, payment and job-report audit events continue to appear without duplicating their source records. Confidential community-case narratives remain in their existing restricted timeline.

## PDF, PNG and preview

Download PDF, Download Image and Preview Report use the same SQL authorization and filters as the table. A read-only repeatable-read snapshot captures **all matching rows**, across pagination, in 250-record query chunks. The export excludes its own subsequently recorded export event. Exactly one successful audit-export event is recorded per complete PDF/PNG/ZIP request, never per page; viewing a preview does not generate export events.

PDF uses the installed Dompdf library, official orange branding, white landscape A4 pages, wrapped cells, repeated table headings and page numbers. PNG uses existing GD/FreeType and bundled fonts: fixed 1240 x 1754 pages with branding, period, applied filters, generated time/actor, complete records and page numbers. One page downloads as PNG; multiple pages download as a ZIP of `Part1`, `Part2`, etc. Zero-record exports explicitly say no records matched.

Filenames reflect the selected daily date, calendar month or inclusive custom range, for example `AuditLog_2026-10-15.pdf` or `AuditLog_2026-10-01_to_2026-10-15.zip`.

Reports are served directly through the authorized endpoint with `no-store, private`; they are never published in an uploads directory. Multi-page ZIP staging uses protected OS temporary files outside public directories, removed in `finally`. The frontend prevents concurrent exports, keeps theme/navigation functionality and applies a 120-second timeout with loading cleanup. List/detail requests have a 30-second timeout.

The configured ceiling defaults to **500 complete matching records** using `CONDO_AUDIT_EXPORT_MAX_RECORDS`. The effective limit also respects available PHP memory, reserving 64 MiB plus a conservative per-record layout budget. The page shows the current effective cap. A larger configured ceiling (up to 2,000) can be used on a sufficiently provisioned server; the memory guard still applies. Oversized requests fail before rendering, with instructions to narrow the period/filters; they never silently export a subset. Dompdf retains layout in memory even when SQL is chunked. On this machine, a 500-record stress PDF took 16.7 seconds and peaked at 222 MiB; a direct 2,000-record stress render exceeded the 512 MiB limit, motivating the guard.

## Schema and deployment

The additive migration adds nullable `actor_name_snapshot`, `actor_identifier`, `actor_role_snapshot`, and `outcome` fields, plus timestamp/actor/module/role/outcome indexes. Existing TIMESTAMP values remain unchanged. Application connections use `+08:00`; SQL compares indexed timestamps against Philippine local midnight boundaries. UTC equivalents are calculated explicitly for compatibility; no formatted-date filtering or server timezone tables are required.

Back up the database and private files, then run:

```text
php scripts/migrate.php --apply
php scripts/preflight.php
```

The repeatable migration installs the same additive schema as the fresh-install `database.sql` definition. Never import the baseline over an existing database. No new provider keys or dependencies are required. The application still targets PHP 8.3; local tests ran on installed PHP 8.2.12.

Modified existing files: `superadmin/auditlog.php`, `includes/audit.php`, `includes/ui_icons.php`, `includes/deployment_schema.php`, `includes/qr_scan_history.php`, `includes/payment_confirmation.php`, visitor parking billing/movement/extension audit call sites, `login.php`, `logout.php`, `database.sql`, `.env.example`, deployment/verification documentation and isolated UI fixture routing.

New files: `includes/audit_report.php`, `includes/audit_report_export.php`, `includes/audit_workspace.php`, `api/audit_log.php`, `audit_log_export.php`, `assets/css/audit-log.css`, `assets/js/audit-log.js`, and the audit integration/browser tests.

## Test commands

```text
php scripts/test_audit_log.php
php scripts/test_ui_redesign.php --audit-only --preview
node scripts/test_audit_log_browser.cjs <printed-loopback-base-url>
```

Press Enter to close the preview and remove its disposable database. Tests use fictional identities, controlled metadata and mocked providers. PDF pages and PNG images were rendered/inspected; they are test artifacts, not reports from resident records. See [VERIFICATION.md](VERIFICATION.md) for local migration preservation checks, regression results and unperformed host checks.
