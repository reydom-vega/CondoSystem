# QR scanner history and reports

The existing scanner now has Scan QR and Scan History tabs. Camera and manual URL verification still use the existing pass verification functions. No second scanner or history table was introduced.

## Database and setup

All fresh-install SQL remains in **database.sql**, including the extended `qr_scan_logs` table. On an existing database run `C:\xampp\php\php.exe scripts/migrate.php --apply`. The repeatable migration records schema version **2026.10.09.4**. Local `CONDO_AUTO_MIGRATE=1` also extends the log table when the scanner API is opened; production web requests remain read-only with respect to schema.

Existing scan IDs and times are preserved. Old raw QR content is redacted because it may contain bearer tokens. Previously unrecorded outcomes and roles are labeled Unknown instead of guessed from current pass data. Old TIMESTAMP values are converted using the migration connection's UTC+08:00 session timezone. New event and creation timestamps use `UTC_TIMESTAMP()` in explicit UTC DATETIME columns; the UI and reports display Asia/Manila time.

No Composer dependency was added: the installed `dompdf/dompdf` is reused. Remote fetching, JavaScript and embedded PHP are disabled in PDF generation. There are no additional required environment settings.

## Recording and permissions

- Admin, Superadmin and Security can scan, search, filter and view history and details through `security.gate`. Security's duty scope includes the centralized visitor, property and visitor-parking events; it has no history editing/deletion endpoint.
- Only Admin and Superadmin have the explicit `scan_history.export` capability. Add a role there only if its report-export permission is intentionally changed. Residents and tenants are denied all scanner/history/report endpoints.
- Scanner ID, full name and role are resolved from the authenticated account. Caller-supplied scanner names/IDs/roles are ignored. All new events snapshot the verified pass identity, reference, unit, outcome and action; changing a pass or deleting its source later does not change past events.
- The POST scanner API requires CSRF and a client request UUID. It serializes same-user/same-content processing with a database advisory lock. Repeated UUIDs are idempotent; unchanged repeated camera detections within five seconds share a record. A changed outcome with a new UUID creates a new event even during that window. A retry always returns current verification feedback while retaining the original event snapshot.
- Unknown or forged tokens do not attach a guessed visitor, requester or unit. Neither raw tokens nor token-bearing URLs are persisted in new history or returned by history/detail APIs. The scanner response may return the verified URL transiently to open the protected pass details.
- Scanning does not move a visitor or complete a permit. Confirmed visitor entry/exit and property completion append separate events in the **same transaction** as their state change. If history cannot be saved, the state change rolls back. Existing status checks plus a unique action key prevent repeat confirmed actions.
- Opening or refreshing the property pass only displays its current validity; it no longer creates a redundant verification event on each page load. Camera/API verification remains the recorded scan boundary.

## Search, filters and UI

The history endpoint performs prepared SQL search across visitor/requester names, unit, pass number, scan ID and scanner name. Search debounces for 300 ms. LIKE wildcard characters entered by a user are escaped and treated literally.

Type, outcome, scanner role and date filters combine. Presets include Today, Yesterday, Last 7 Days and This Month, plus single-date and custom-range pickers. Date boundaries are converted from Manila midnight to UTC using a start-inclusive/end-exclusive interval, including every second of the selected end date. Results have 25-row server pagination, stable newest/oldest sorting, matching counts, filtered summary cards and a details dialog. Successful scans refresh history; returning from a pass confirmation window refreshes it as well.

The Invalid summary includes Invalid and Already Used events and is labeled accordingly. Historical records whose original outcomes were not stored remain Unknown. Blue action labels distinguish confirmed movements from verification.

## PDF export

Open Export to PDF in Scan History. Choose a date or period and optional filters, or retain the active search/type/status/role filters. The date selection is copied from history when applicable; an unbounded history defaults to Today for export.

Exports query all matching records across pagination using the same filter builder as the table and a consistent database snapshot. Empty results return a friendly message. Reports are limited to 2,000 events and 366 days; exceeding either limit returns an error, never a silently truncated PDF.

Reports are landscape A4 with THE CELANDINE HOMES branding, selected filters, generated-by identity, Manila generation time, summaries, repeated table headers, reference and numbered footers. The browser downloads `qr_scan_history_START_to_END.pdf`. No generated report or private report file is publicly stored on the server.

## Files

New: `includes/qr_scan_history.php`, `includes/qr_scan_history_content.php`, `includes/qr_scan_report.php`, `api/scan_history_export.php`, `js/qr-scanner.js`, `scripts/test_qr_scan_history.php`, and this guide.

Updated: `api/scan_history.php`, `includes/access_scanner.php`, `includes/qr_scanner_page.php`, `includes/authorization.php`, `includes/resident_services.php`, `includes/visitors.php`, `includes/property_gate_pass.php`, `includes/property_gate_pass_page.php`, `config.php`, `scanner.css`, `includes/deployment_schema.php`, `scripts/migrate.php`, `database.sql`, the isolated `scripts/gate_fixture_router.php`, and the authorization/property regression tests. The existing staff navigation automatically exposes the scanner for Admin after its capability is enabled.

## Validation

`php scripts/test_qr_scan_history.php` uses a disposable database and loopback HTTP server to test real verification logging, invalid/expired/cancelled/replayed passes, authenticated identities, deduplication, action rollback, literal search, combined filters, UTC/local boundaries, pagination, full-result PDF data, export resource limits, all role checks, CSRF, details and actual PDF downloads. Optional `--pdf=<private path>` writes the fixture PDF for layout QA; it contains only synthetic test data.

The property, resident workflow, authorization and deployment suites provide regression coverage. The generated multi-page report is rendered and inspected for table layout, repeated headers and footers. Live camera permission/scanning and browser interactions require a connected browser/device; no browser was connected during this implementation.
