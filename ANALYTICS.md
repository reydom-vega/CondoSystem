# System Analytics

The existing `superadmin/analytics.php` is the entry point. There is no duplicate dashboard or transaction warehouse. The legacy `getAnalytics()` helper remains available to the existing management dashboards.

## Access and data protection

The existing `analytics.read` permission remains **Super Admin only**. Admin, Treasurer, Security, Maintenance, owners, tenants and guests have not gained global analytics access. Every aggregate, detail and download request checks the current database account, active/approved/verified state, role and session version. Section access additionally follows the original module capabilities. Destination pages retain their own guards.

API and download POSTs require the existing CSRF token. Sensitive responses use `Cache-Control: no-store, private`. SQL values use prepared statements; identifiers come from fixed server-side definitions. Charts contain aggregate labels and counts, not resident names, visitor documents, QR contents, passwords, payment credentials, complaint evidence or report narratives. Staff lookup returns authorized filed-report metadata, not private work notes. System activity reuses the audit module's restricted projection, including its confidential-case redaction.

## Filters and time

Default: **This Month**, daily trend grouping. Today, Monday-based week, calendar month, calendar year and inclusive Custom Date Range are available. Daily, monthly and yearly grouping affects trend buckets; it does not change the underlying sums. Custom ranges run from 2000 through today. At most 420 trend buckets/categories are rendered per chart; larger requests receive an explicit narrowing/grouping instruction.

All displayed times use **Asia/Manila**. Local TIMESTAMP/DATETIME sources use the application's `+08:00` connection convention. Parking sessions and filed staff reports use UTC DATETIME fields; their boundaries are converted before the query and their chart buckets are converted with numeric timezone offsets. WHERE clauses do not convert indexed event columns.

Periods are start-inclusive and cutoff-exclusive. A current period stops at capture time. Complete historical periods compare with the previous equivalent period; current periods compare with matched elapsed time in the previous period, capped at the previous calendar boundary. No percentage is produced from a zero/missing baseline. Custom ranges compare against an immediately preceding range of the same duration.

Each retrieval uses a read-only repeatable-read consistent snapshot. All sections share its cutoff. Detail lists have their own consistent snapshot. Refresh updates all sections together. A failed section is explicitly unavailable while unrelated sections remain usable. The dashboard does not run parking monitoring, update billing, repair data or create activity records during refresh.

Staff ID/name/role lookup applies **only to Staff Activities** and is clearly labeled. Apply/Clear/Refresh use the same filters as reports. Each card identifies whether it represents a period event, a current cohort status or current live state.

## Metric definitions and limitations

| Section | Authoritative sources and rules |
| --- | --- |
| Overview | Actual unit inventory, verified period collections, current unpaid balance, current visit parties/vehicle sessions, unresolved maintenance and authorized pending decision queues. Linked queues may require separate decisions; the sum is not a unique-person count. |
| Financial | `payments` counted once per invoice. Billed = current stored amounts for paid/pending/overdue invoices created in the period, excluding rejected/rolled-forward records. Collected = paid invoices with `paid_at` in the period, including authorized offline settlement. Retry/webhook/audit rows are never summed as revenue. Outstanding = current pending/overdue amounts across all creation dates. Overdue = those unpaid invoices with due date before today. |
| Financial categories | Real `bill_items` only when their sum reconciles to the invoice. Otherwise the entire invoice is explicitly Unallocated / reconciliation. Category totals therefore reconcile to invoice totals without multiplying one-to-many joins. |
| Collection rate | Amount of the same creation-period invoice cohort paid before the reporting cutoff divided by the cohort's billed amount. Period receipts and newly created bills can concern different invoices and are separately labeled. |
| Units | Existing `inventory/condo_units.csv` via `loadUnitInventory()`, normalized/distinct unit numbers and approved owner assignments using the original Unit Monitoring rule. Tenants do not create a second occupied unit. Current occupancy is assignment-based, not physical presence. Floor counts come from this inventory. No hardcoded project total. |
| Visitors | `resident_service_requests` for registration creation/status; `visitor_logs.time_in/time_out` for actual confirmed movements. A record is a visit party; people counts sum `visitor_count`. Repeated QR verification records do not create additional analytics entries. Live visitors are checked-in parties without checkout. |
| Visitor parking | Actual UTC entry/exit on `parking_requests`. Finalized charges use stored fee/snapshot and linked invoice records; collections use the uniquely linked positive paid invoice and its local payment date. Free sessions and unresolved rate/account reviews are excluded from finalized financial totals. Charge period uses review-finalization timestamp, or checkout when no review occurred. Analytics never invokes or copies the pricing calculator. |
| Parking duration/inventory | Nonnegative completed actual durations, stored overnight/grace components, stored compliance, real visitor slot states. Reserved assignments and out-of-service slots stay distinct. The occupancy denominator includes all visitor slots and is documented. Extended entries describe actual duration, not a new determination of policy compliance. |
| Maintenance | New requests by creation date; current status of that cohort; live unresolved pending/approved/in-progress/reopened backlog. Cohort resolution rate = completed plus closed divided by created requests. `updated_at` is not treated as an immutable completion date. No invented SLA. |
| Amenities | Creation-period request statuses and separate scheduled-date charts. Scheduled counts include approved/confirmed/completed only; rejected/cancelled reservations are excluded. Reservations are not proof of attendance or capacity utilization. |
| Violations | Confirmed legacy/approved records only. Legacy issuance uses `created_at`; reviewed vehicle reports use `reviewed_at`. Pending allegations remain separate. Fine collections sum each actual linked bill item once on a reconciled invoice, even if multiple fines share one invoice. Repeat counts mean responsible accounts with multiple confirmed records, not proof of one individual offender or a ranking. |
| Staff | Immutable `job_reports` filed timestamp and recorded staff metadata. Each filed version is counted; corrections do not erase earlier versions. Audit-based staff work counts exclude passive views/refreshes/ticks, authentication and exports. No quality/productivity score is inferred. |
| System activity | Central audit IDs counted once using their recorded timestamp/module/role/outcome. Passive views/refreshes/ticks excluded. Unknown outcomes remain unknown. Legacy `analytics` usage telemetry and resident logins remain separately labeled and are not added to work totals. |
| Data Quality | Read-only orphan account, invoice-item reconciliation, undated paid/approved records, missing parking invoice, slot/session inconsistency, outstanding rate/account review, missing fine linkage and stale service-request checks. Detail lists contain safe IDs/references with source links. |

**Unavailable** is deliberate where the installation lacks an authoritative source: historical balances/adjustments/refunds/partial settlements, reserved condominium status, historical occupancy, stable unique visitor identities, maintenance completion timestamps and physical amenity capacity/attendance. Infrastructure CPU/RAM/disk/uptime/backup status and unprocessed webhook queues show **Monitoring not configured**. No additional ledger or instrumentation is invented by this upgrade.

Existing mutable billing/status records can change a previously viewed creation-cohort report. These reports are management summaries, not immutable financial statements. They do not reconstruct historical balances from today's state.

## Insights and drill-down

Insights state their metric, time basis and configured rule. They identify parking occupancy thresholds, unresolved maintenance backlog, filed versions awaiting review, overdue billing, comparable visitor growth and unresolved pricing/account reviews. Visitor growth requires both a positive previous baseline and at least ten additional entries. No causal explanation, employee ranking or blanket health certification is generated.

Occupancy segments and maintenance/booking statuses open existing supported module filters. Approval queue segments open their authorized management views. Unresolved maintenance, staff filings and data-quality cards also provide paginated safe record lists. Staff rows open the existing Job Report viewer. Audit links carry validated date/module/role filters into the existing Audit Log; all destination permissions remain enforced. Where a source module lacks a supported date filter, its link is labeled **Open source module** instead of pretending it is a matching historical record list.

## PDF and image reports

Choose **Entire Authorized Analytics Report** or one section in **Report scope**, then Download PDF or Download Image. Reports use the applied period/grouping/staff lookup and a fresh consistent server snapshot with a displayed generation/cutoff time. They are generated from complete aggregate datasets, not from a dashboard viewport screenshot.

- PDF: bundled Dompdf, landscape A4, official branding, exact totals and definitions, relevant charts, repeated table headings, notes, insights and page numbers. The existing `?export=pdf` bookmark remains an authorized HTML print preview.
- PNG: bundled GD/FreeType/fonts, 1240 × 1754 pages. A short report downloads one PNG. Longer reports download a ZIP with **every numbered page**, each with branding and period. PNG category charts use bars with the same underlying values as the dashboard's donut charts; complete exact value rows are included. No rows are silently dropped.
- Names: `TheCelandine_Analytics_2026-10.pdf`, `TheCelandine_FinancialAnalytics_2026-10.pdf`, or `_2026-10-01_to_2026-10-15` for a custom range. Numbered images append `_Part1.png`, `_Part2.png`, etc.
- ZIP staging is private, outside public directories, random-named, permission restricted and removed in `finally`. One successful audit export event is recorded per complete download, not per page. GET previews do not mutate history.
- Default aggregate-row rendering ceiling is 500 with an additional memory guard and a maximum configurable ceiling of 1500. Metrics and both series' exact chart rows count toward the limit. A long daily report may require monthly grouping, a narrower period or one section. Rejection is explicit; the full report is never silently shortened.

## Settings, deployment and files

Optional settings documented in `.env.example`:

| Setting | Default |
| --- | --- |
| `CONDO_ANALYTICS_EXPORT_MAX_ROWS` | 500 |
| `CONDO_ANALYTICS_STALE_DAYS` | 30 |
| `CONDO_ANALYTICS_PARKING_THRESHOLD` | 85 percent |
| `CONDO_ANALYTICS_MAINTENANCE_THRESHOLD` | 20 unresolved requests |
| `CONDO_ANALYTICS_REPORT_THRESHOLD` | 5 submitted versions in filing cohort |
| `CONDO_ANALYTICS_OVERDUE_THRESHOLD_CENTS` | 100000 centavos |
| `CONDO_ANALYTICS_GROWTH_THRESHOLD` | 20 percent plus 10 entries |

Back up first, then run `php scripts/migrate.php --apply` and `php scripts/preflight.php`. Release **2026.10.15.3** adds reporting indexes only; no new transaction tables, financial backfills or status changes. Index creation may briefly lock large tables: deploy during a suitable maintenance window. The migration is repeatable and validates index columns before recording the version. Base `database.sql` also includes the indexes for new installations.

Implementation files:

- Existing page: `superadmin/analytics.php`.
- UI: `includes/analytics_workspace.php`, `assets/css/system-analytics.css`, `assets/js/system-analytics.js`.
- Services: `includes/system_analytics.php`, `analytics_financial.php`, `analytics_operations.php`, `analytics_management.php`, `analytics_charts.php`, `analytics_export.php`, `analytics_schema.php` in `includes/`.
- Guarded routes: `api/system_analytics.php`, `analytics_export.php`.
- Integration: `config.php`, `database.sql`, `includes/deployment_schema.php`, `scripts/migrate.php`; existing audit source/workspace/JavaScript for Analytics export classification and filter-preserving drill-down.
- Validation: `scripts/test_system_analytics.php`, `scripts/test_system_analytics_browser.cjs`, existing disposable UI fixture/router.

## Verification

Run `php scripts/test_system_analytics.php` for controlled date boundaries, money reconciliation, actual movements, overnight/free/review separation, issuance dates, immutable filed metadata, lookup isolation, privacy, role checks, partial failure, read-only integrity, migration idempotency and real PDF/PNG/ZIP output. It creates and drops a private disposable database and contacts no provider.

Run `php scripts/test_ui_redesign.php --analytics-only --preview`, copy its loopback URL into `node scripts/test_system_analytics_browser.cjs <fixture-url>`, then press Enter in the preview process to remove the fixture. Chrome tests all tabs, native chart values, filters, drill-down, downloads, CSRF/role rejection, offline cleanup, theme persistence and widths 1366/768/390/320. Generated QA images and reports stay in the OS temporary directory, outside the web root.

Test results and local migration evidence are recorded in `VERIFICATION.md`. This environment runs PHP 8.2.12; PHP 8.3 and production-host behavior require deployment verification. No provider delivery or live payment was used as a test.
