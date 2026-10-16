# Local release verification

Reviewed on 8 October 2026 for schema release `2026.10.08.2`.

## Automated results

| Suite | Passed checks |
| --- | ---: |
| Resident services, visitors/parking, vehicles/stickers, bookings and rendered forms/JavaScript | 169 |
| Authentication, recovery, CSRF, remembered login and session revocation | 47 |
| Role guards, account lifecycle, staff permissions and notification retry controls | 142 |
| Billing, violations, payment validation and atomic settlement | 69 |
| Notification deduplication, retries, relevance and receipt rollback | 44 |
| Tenant signup, permissions, unit owner links, occupancy removal and rendered route controls | 178 |
| Tenant read-only bills, owner payments/stickers, fines and financial notification routing | 131 |
| Fresh installation, repeated migration and deployment guards | 26 |
| Backup manifest failures, scoped restoration and exact owner-link migration validation | 72 |
| **Total** | **878** |

The hosted PayMongo bank-checkout checks also passed. Composer's locked dependency audit reported no security advisories or abandoned packages. PHP syntax and Git whitespace checks passed. Delivery tests use injected senders; payment tests use fixtures and do not contact providers. Database fixtures are uniquely named and removed after use; restore fixtures also remove their generated restricted accounts.

## Existing local installation

The database and private uploads were backed up outside the web directory at `C:\xampp\backups\CondoSystem3\snapshot_20261008_124508_8f1c7e85` before the tenant policy update. The backup contains a checksum manifest; configuration, code release and environment secrets need separate protected backups.

The database backup was restored into a disposable database using a temporary account with privileges only on that database. Migration and read-only preflight passed, existing account/workflow/financial state fingerprints matched, and the temporary database/account were removed. The working local installation was then migrated to `2026.10.08.2`. Restoration validation permits only the precomputed owner-link backfill and its single session-version increment; all other existing protected account, workflow and financial fields must match, including binary data. Fixtures verify that ambiguous or unmatched tenants remain unlinked and unauthorized data changes fail validation.

Local preflight with `--http=http://localhost/CondoSystem3` passed with zero failures and zero warnings. Thirteen protected artifact URLs returned 403; the application entry point returned 302 and its stylesheet returned 200. Preflight reads settings/schema and HTTP responses; it does not make provider calls or write business data.

Tenant checks exercised real page/API routes using disposable accounts. Forged signup/resubmission fields cannot grant owner or staff authority. Tenants can read bill amounts and line items but cannot initiate payment, open receipts or retrieve gateway details. Owners can sponsor approved linked tenant vehicles; new fines and financial notices use the owner account. Ending one occupancy preserves the owner and other tenants, while owner removal revokes linked occupants. Current owner relationships govern access to untouched legacy invoices; see [TENANT_CONFIGURATION.md](TENANT_CONFIGURATION.md) for management procedures.

## Production checks still required

Use [DEPLOYMENT.md](DEPLOYMENT.md) to configure and verify the actual host. Production preflight requires updated PHP 8.4+, HTTPS, a dedicated database account, disabled runtime migrations and new private credentials/signing secrets. Previously exposed credentials must be revoked at their providers.

Schedule the notification worker and reminder job, verify actual PayMongo callbacks and reconciliation in the matching provider mode, and test SMTP/SMS delivery with controlled recipients. Check desktop/mobile appearance, camera permissions and one complete gate workflow on the intended devices. Automated DOM/JavaScript checks do not verify browser appearance, camera hardware or external provider delivery. This record establishes local validation; it is not a report of a public production deployment.

## Celandine email receipts - 14 October 2026

New hosted checkouts set `send_email_receipt=false`, so the application issues its own branded, itemized email receipt after verified PayMongo settlement. Receipt data comes from the paid ledger record: DUES reference, amount, payment date/channel, confirmed PayMongo payment ID, resident/unit and bill items. Cash receipts use the same template with the recorded cash method. Email and the existing protected PDF share the paid-receipt data helper. The white email uses the existing orange Celandine PNG/CID brand asset for mail-client compatibility; the PDF continues to use the orange SVG.

The existing transactional outbox, authorized financial recipient routing, retries and event deduplication are preserved. A failed enqueue still rolls back settlement; duplicate paid callbacks do not enqueue another receipt. Unpaid bills cannot produce receipts. The PDF link requires the existing authorized account login. Already-created provider checkout sessions retain their original email setting; this change does not resend past receipts or cancel active checkouts. Other PayMongo dashboard-created links are outside this integration.

Verification uses disposable databases and injected delivery; no real recipient is emailed and no provider checkout is created by these tests. Live SMTP acceptance and the next new PayMongo checkout still need observation in the configured environment.

Receipt verification passed 58 notification checks, 72 payment workflow checks and 163 tenant billing/receipt-access checks (293 total), plus hosted bank mapping and PHP syntax checks. The email layout was rendered and inspected locally. Receipt-email control follows the [PayMongo Checkout Session receipt flag](https://docs.paymongo.com/reference/checkout-session-resource). The existing minute notification worker reported a successful queue check after this change.


## Visitor parking fees and extensions - 14 October 2026

Schema release `2026.10.14.3` was applied to the local installation with the repeatable visitor parking migration. Existing movements and invoices were retained; no historical parking fee was created. Read-only preflight reported zero failures, with only the explicit warning that HTTP artifact-denial probes were not requested in that invocation.

| Disposable-database suite | Passed checks |
| --- | ---: |
| Visitor parking prices, vehicle lifecycle, extensions, invoice/receipt links and rollback | 95 |
| Resident workflows, parking/sticker/vehicle integration and rendered forms | 298 |
| Authorization and occupancy lifecycle | 221 |
| Tenant billing and receipt permissions | 163 |
| Visitor ID uploads, QR approvals, privacy and retention | 87 |
| Existing payment workflow and provider confirmation guards | 72 |
| Notification retry/deduplication and settlement rollback | 58 |
| Fresh schema and repeatable deployment migration | 27 |
| **Backend total** | **1,021** |

The dedicated Chrome parking suite passed 68 checks across Unit Owner, Tenant, Admin, Super Admin and Security: dark/light modes, widths 1366/768/390/320, real entry/checkout buttons, management extension decisions, billing privacy, expected fee validation and the protected paid PDF route. The existing parking/sticker workspace Chrome regression suite also passed 56 checks, including legacy URLs, vehicle uploads/approvals, inventory mutations and sticker billing. Visible form-field checks cover the shared label CSS rule and Yes/No vehicle controls. The focused route suite passed 339 asset/rendering/CSRF checks across 17 role/page combinations. The paid visitor-parking receipt was downloaded through the existing authenticated route, rendered with Poppler and visually inspected as one readable A4 page with the orange logo and full session/payment details. PHP lint passed for 51 modified/new PHP files at the verification pass; JavaScript syntax and Git whitespace checks passed.

The existing Windows task `CondoSystem Notifications` was inspected, and its private heartbeat confirmed a successful parking monitoring run on this local machine. Monitoring batches rotate through active sessions and also expire pre-arrival requests. Parking failures are isolated from unrelated notification delivery. See [VISITOR_PARKING_FEES.md](VISITOR_PARKING_FEES.md) for policy, legacy acknowledgment, payer snapshots, scheduling and test commands.

All business-flow fixtures ran in disposable databases. No live PayMongo transaction or controlled SMTP/SMS delivery was performed by these tests. The local PHP binary reports 8.2.12; PHP 8.3 execution still needs confirmation on the target runtime. Camera hardware, production HTTP protection and live provider delivery remain operational checks.

## Combined visitor parking approval - 14 October 2026

Admin and Super Admin now review the actual private ID, visit, sponsor/unit, vehicle, fee estimate, assigned/requested slot and optional planned extension in one shared window. One submit reuses the existing identity, visit, parking and extension decision functions inside a single transaction. The individual functions retain their original behavior for existing callers. Separate review and rejection options remain available, while completed requests no longer present the combined approval button.

The expanded parking backend suite passed **122 checks**, including role restrictions, stale/replaced ID protection, slot and extended-period conflicts, a late audit failure, complete rollback of earlier decisions and queued notices, repeat-submit idempotence, partially reviewed requests, QR eligibility and no premature parking timer or bill. The visitor identity suite passed **87 checks** and resident workflow suite passed **298 checks** after the transaction composition changes.

The dedicated combined-approval Chrome suite passed **60 checks** across Admin and Super Admin from both management entry points, resident/tenant/Security restrictions, unavailable-ID handling, one-submit completion, planned extension approval, repeat submission, and dark/light layouts at 1366/768/390/320 pixels. The focused route fixture passed **279 asset, markup and CSRF checks across 12 role/page combinations**. The final centered dialog layout was additionally inspected in desktop/mobile and both themes. PHP/JavaScript syntax and Git whitespace checks passed. All fixtures use isolated databases and fictional private ID images; no live approvals or provider calls were performed. No schema migration is needed for this UI/workflow improvement.


## Staff job reports

Run `php scripts/test_job_reports.php` for disposable-database activity/time-boundary, RBAC/IDOR, draft, snapshot immutability, submission/review/correction/idempotency, sanitized PDF/PNG/ZIP and mocked notification tests. Run `php scripts/test_ui_redesign.php --jobs-only --preview`, then `node scripts/test_job_reports_browser.cjs <printed loopback base URL>` for browser workflows. Press Enter to clean up the preview fixture. The Staff Reports tab must be first/default; Staff Information and Add Staff Account remain usable. Personal Super Admin access is own-only; central review is Super Admin-only and prohibits self-review. Verify both themes and mobile layout, actual PDF rendering, complete numbered PNG pages, and configured notification-worker delivery. See JOB_REPORTS.md for precise source, metric, filter and export-limit definitions.

Job-report implementation verification (2026-10-15): 136 provider-free backend checks; 45 browser workflow/theme/mobile checks plus 6 Super Admin self-file/resident-tenant HTTP access checks; 1,808 shared UI/asset/CSRF checks across 99 role/page combinations; 225 authorization/lifecycle checks; 72 payment checks; 298 resident workflow checks; 58 mocked notification checks. PHP/JavaScript syntax and git whitespace checks passed. Long PDFs were rendered and inspected for repeated headers, pagination and complete notes; PNG page/ZIP generation, Unicode wrapping, keeping headings with content and rejecting public staging were verified. The additive migration ran repeatedly after a private backup; final preflight had zero failures. Tests ran on the installed PHP 8.2.12, not a PHP 8.3 runtime. Actual SMTP/PayMongo delivery and the Apache public-artifact HTTP preflight were not exercised; browser tests used the isolated PHP loopback fixture.

## Visitor parking policy v2 — 15 October 2026

Schema `2026.10.15.1` was applied locally twice to verify idempotency. Before migration, SQL/private uploads and external visitor-ID storage were backed up outside the web root at `C:/xampp/condo_backups/visitor_parking_v2/snapshot_20261014_205652_4b500c15`. After migration, every original column value and row count matched the pre-migration hashes for all 7 parking requests, 37 payments and 105 bill items. One initial rate version exists. No resident invoice was generated by migration.

Read-only preflight passed with zero failures. Its one warning records that optional HTTP artifact-denial probes were not requested. MySQL was stopped when final checks resumed; normal startup succeeded without database repair or file removal.

| Automated verification | Passed checks |
| --- | ---: |
| Centralized rate boundaries, overnight examples, multi-night segments and legacy rates | 67 |
| V2 lifecycle, consent, free/review checkout, snapshots, verified payment and rollback | 47 |
| Existing parking fee, extension, gate, invoice and receipt regressions | 122 |
| Visitor identity, protected uploads and QR authorization | 87 |
| Resident service workflows | 301 |
| Tenant billing and privacy | 163 |
| Payment workflows | 72 |
| Notification outbox with mocked delivery | 58 |
| Authorization and account lifecycle | 226 |
| Fresh install, repeat migration and deployment readiness | 27 |
| Job-report activity, privacy and exports | 136 |
| Shared UI route/assets/CSRF across 22 role/page combinations | 447 |
| Chrome: five roles, both themes, four widths, movements, fees and receipts | 68 |
| Chrome: staff-assisted registration, sponsor consent and future rate configuration | 9 |
| Chrome: scanner session selection and unchanged movement timestamps | 3 |

Changed PHP files pass syntax checks; changed JavaScript passes Node syntax checks; `git diff --check` reports no whitespace errors. The paid visitor parking PDF was rendered with Poppler and visually inspected: its complete metadata, rate breakdown, charge, total and footer fit on one A4 page. Existing non-parking receipt spacing is unchanged.

The tests use disposable databases, fictional identities and mocked providers. PHP 8.2.12 is installed locally; an actual PHP 8.3 runtime was not available for execution. Live PayMongo settlement, real email/SMS receipt delivery and physical camera hardware were not exercised. Late-arrival overnight eligibility deliberately defaults to management review until PMO confirms its official rule. See [VISITOR_PARKING_FEES.md](VISITOR_PARKING_FEES.md) for policy, operational workflow and test commands.

## Audit Log enhancement - 15 October 2026

Schema `2026.10.15.2` was applied locally twice after a private backup at `C:/xampp/condo_backups/audit_log_v2/snapshot_20261014_213023_de41fa24`. All original column values and IDs of the 750 existing audit records matched their pre-migration SHA-256 fingerprint. New snapshot/outcome columns remain NULL on historical records; no invented outcomes or activities were backfilled. Read-only preflight reports zero failures, with the existing warning that optional Apache HTTP artifact-denial probes were not requested.

| Verification | Passed checks |
| --- | ---: |
| Audit dates, midnight/month/year boundaries, search, filters, summaries, pagination, safe details, snapshots, authorization, complete PDF/PNG/ZIP export and Job Report compatibility | 129 |
| Chrome: filters, details, export/preview, CSRF, seven unauthorized actor states, both themes, 1366/768/390/320 widths, mobile field labels and desktop segmented controls | 52 |
| Audit page shared route, asset and CSRF fixture | 27 |
| Authorization/account lifecycle regression | 226 |
| Payment workflows with mocked gateway | 72 |
| Notification outbox with mocked delivery | 58 |
| QR scan history/deduplication | 81 |
| Visitor ID/privacy/QR workflow | 87 |
| Visitor parking v2 lifecycle/payment/receipt | 47 |
| Parking extension/gate/billing regression | 122 |
| Resident workflows | 301 |
| Staff Job Report integration/privacy/exports | 136 |
| Fresh install, idempotent upgrade and deployment readiness | 27 |

The actual daily SQL plan uses an indexed `range` scan of `audit_logs.created_at` and a primary-key user lookup. Audit list/complete-export queries use read-only repeatable-read snapshots. PHP/JavaScript syntax and Git whitespace checks pass. PDF first/last pages and high-resolution PNG pages were rendered and visually inspected for readable metadata, repeated headers, wrapping and page numbers. An authorized 185-record fixture PDF produced 13 landscape A4 pages; its image export included all 19 numbered PNG pages in a real ZIP. Zero-record PNG/PDF/preview behavior is explicit.

A direct 2,000-record Dompdf stress render exhausted the installed 512 MiB memory limit. The implementation now bounds complete exports before rendering using both the configured ceiling (default 500) and a conservative available-memory budget. A 500-record stress PDF passed in 16.7 seconds at a 222 MiB peak. Oversized requests return an explicit error instead of a partial report. Generated ZIP staging is private and removed after download.

Tests use disposable databases and fictional activities; they do not export real resident records or call external providers. Execution used installed PHP 8.2.12, not a PHP 8.3 runtime. Hostinger/InfinityFree deployment, physical camera hardware, actual SMTP/SMS/PayMongo delivery and optional Apache artifact-denial probes were not exercised by this enhancement. Existing authentication/payment flows and confidential community-case timelines were preserved; new logging adds only safe action records/outcomes. See [AUDIT_LOG.md](AUDIT_LOG.md) for files, access rules, redaction, limits and deployment/test commands.

## System Analytics upgrade (2026-10-15)

The existing Super Admin Analytics page now provides ten authorized sections, shared Manila-date filters/grouping, matched-period comparisons, native SVG charts, exact value tables, source/drill-down links, staff lookup, explicit rule-based insights, data-quality investigation and complete server-generated PDF/PNG reports. No duplicate transaction store or extra chart dependency was introduced. Existing `analytics.read` access remains Super Admin-only.

Schema `2026.10.15.3` was applied locally twice after a private SQL/file backup at `C:/xampp/condo_backups/analytics_v2/snapshot_20261014_224206_3cf4e2f4`. All original rows/columns matched their pre-migration SHA-256 fingerprints across 13 tables: payments, bill items, parking requests/slots/extensions, visitor logs, service requests, maintenance, bookings, violations, filed Job Reports/reviews and audit history. This upgrade adds reporting indexes only. Preflight validates their column order; a missing reporting index is covered by a negative test. Read-only deployment preflight reports zero failures and the existing warning that optional HTTP artifact-denial probes were not requested.

| Verification | Passed checks |
| --- | ---: |
| Controlled analytics calculations, midnight/UTC/leap/420-point bounds, cohort comparisons, zero baselines, unique payment/fine allocation, actual parking movements, finalized grace/overnight/review separation, missing timestamps, authorization, lookup isolation, safe pagination, partial failure, read-only integrity, repeat migration and complete PDF/PNG/ZIP | 149 |
| Chrome: all ten tabs/charts, exact values, presets/custom ranges, details/Escape, staff lookup, visible report-scope selector, actual PDF/PNG ZIP, oversized export rejection, CSRF, seven unauthorized actor states at API and export, Audit Log filter-preserving links, offline/recovery cleanup, theme persistence and 1366/768/390/320 widths | 74 |
| Analytics page shared route/asset/CSRF fixture | 27 |
| Audit query/privacy/pagination/export regression | 129 |
| Authorization/account lifecycle regression | 226 |
| Payment workflows with mocked gateway | 72 |
| Staff Job Report snapshot/review/privacy/export regression | 136 |
| Visitor parking v2 lifecycle/pricing/billing/receipt regression | 47 |
| Fresh-install/idempotent-upgrade/deployment regression | 27 |

A read-only capture against the installed database returned all ten sections with zero unavailable sections in approximately 0.48 seconds. This is a small local-data smoke check, not a production load benchmark. Controlled fixtures verify PHP 725.00 newly billed, PHP 205.00 actually collected, PHP 500.00 live outstanding and a 14.48% same-invoice-cohort collection rate without multiplying multi-item invoices. Approved violations created on the previous day count by their actual review/issuance date; two fines sharing one paid invoice allocate PHP 30.00 once. Unreconciled fine items cannot inflate collections. Analytics reads and repeated index migration leave business/history records unchanged.

Actual exports were rendered and visually reviewed for branding, readable charts/labels, wrapping, exact values, period/time basis, page breaks and numbering. The full one-day fixture creates a multi-page landscape A4 PDF and all numbered 1240 x 1754 PNG pages in a ZIP. Short-image behavior is also verified. Poppler and pdfplumber confirmed text on every PDF page and expected money values with no private fixture narratives/tokens. Private ZIP staging is removed after download; one successful audit event is recorded per complete export. Oversized reports fail explicitly and advise grouping/filter/scope changes rather than omitting data. Native charts keep full accessible values and a focusable contained scroll region on narrow screens.

PHP syntax checks passed for 59 changed/new PHP files, JavaScript syntax for seven changed/new JS/CJS files, and Git whitespace validation. Provider delivery, actual live payments, cameras, PHP 8.3 execution and Hostinger/InfinityFree deployment were not exercised. Installed runtime is PHP 8.2.12. Historical balances/refunds/adjustments, occupancy history, immutable maintenance completion dates, stable unique visitor identities and infrastructure/webhook instrumentation remain explicitly unavailable/not configured where no authoritative data exists. See [ANALYTICS.md](ANALYTICS.md) for source rules, filenames, settings, migration and test commands.

## Tenant booking payment responsibility (2026-10-15)

Pool reservation approval now creates the existing `amenity_reservation` invoice on the requesting approved tenant's account. The invoice helper validates the expected payer before inserting a charge. Payment authorization requires both the tenant's billed account and their own linked booking; the owner and other residents cannot initiate/resume its payment or download its receipt. Owner/other-occupant bookings retain their existing billing accounts, shared unit billing permissions remain intact, and Function Hall retains its existing PMO payment arrangement. Booking approval/payment-status UI and payment-page wording reflect tenant responsibility. Existing notification/receipt helpers now resolve tenant reservation notices to the tenant through the shared payer check.

One installed approved booking (#7) had a pending, unbilled invoice (#39) on its owner account, with no checkout/payment reference or settlement. After a private SQL/file backup at `C:/xampp/condo_backups/tenant_booking_payer/snapshot_20261014_230719_6e535d0e`, a locked transaction changed only its payer from owner account #17 to requesting tenant account #24 and recorded an automated `billing_payer_corrected` audit event. Exact comparisons verified unchanged amount, bill items, booking and payment status; tenant payment permission succeeds and owner payment permission fails. The backup also contains the original booking/invoice/item JSON. No provider was contacted or live notification dispatched during this correction. No schema migration was required.

Validation passed: 203 tenant billing checks, 138 pool/gallery/billing checks, 72 payment workflow checks, 226 authorization/lifecycle checks and 301 resident workflow checks, all using disposable databases and provider-free fixtures. Coverage includes wrong-payer rejection without an invoice, duplicate approval, tenant-only payment controls, forged owner payment denial, cash settlement, gateway-adapter authorization, saved checkout resume, verified/retried gateway settlement, linked booking confirmation, tenant notice/receipt routing, receipt/status routes and unchanged owner booking liability. PHP syntax passed for all 15 touched booking/payment PHP files; Git whitespace validation passed. Real gateway/SMTP delivery was not exercised.

## Security role cleanup and dashboard (2026-10-15)

Removed Registered Vehicles, Visitor Registrations and Parking & Stickers from Security navigation and denied their direct GET/POST management routes. Security no longer holds vehicle registry read, visitor approval/identity-review or parking management-review capabilities. Admin/Super Admin retain their management access. Incident-specific vehicle lookup uses the separate `vehicles.lookup` capability; QR visitor/parking entry and checkout retain their gate permissions. Approval fixtures now use management accounts before Security performs gate operations, matching the revised role policy.

The existing Security dashboard now shows actual people currently inside, the guard's own pending incident reports and own QR verification count for the Philippine day, plus recent visitor movements, own report metadata and three authorized quick actions. Global parking approval queues and unrelated violation narratives were removed. A guarded read-only snapshot API replaces the broken admin-dashboard poller, with visible-tab refresh, bounded/non-overlapping requests, offline/stale-data feedback, cleanup and working profile dropdown controls. Native icons, branding, dark/light themes and responsive shared components are preserved. No schema or business-data migration was performed.

| Verification | Passed checks |
| --- | ---: |
| Security counters, Philippine-day UTC boundaries, own-report scope, private-field exclusion, permissions, revoked session and read-only integrity | 30 |
| Chrome dashboard/sidebar, both themes, 1366/768/390/320 widths, touch targets, profile/Escape, refresh/error recovery, direct-route denial, other-role API denial and retained QR Scanner | 37 |
| Security page/Scanner/Violations/Job Report shared route, asset and CSRF fixture | 81 |
| Authorization/account lifecycle and removed-feature GET/POST denial | 257 |
| Visitor ID/privacy/QR workflow | 88 |
| Visitor parking v2 lifecycle/fees/payment/receipt | 47 |
| Visitor parking fee/extension/gate/billing/receipt regression | 122 |
| QR history, actor snapshots, deduplication, entry/exit rollback and history privacy | 81 |
| Incident vehicle lookup/history/review/billing/HTTP workflow | 72 |
| Resident workflow regression | 303 |

Desktop dark and mobile light screenshots were inspected for readable cards, spacing, navigation, empty states and alignment. PHP syntax passed for all 23 touched PHP files, new JavaScript/browser-test syntax passed, and Git whitespace checks passed. Tests use disposable databases; no providers or physical cameras were contacted. A direct installed-data smoke check found no currently active, verified, approved Security account, so it did not impersonate or change an existing user's status; browser/account tests used isolated approved Security fixtures. PHP 8.3/hosting and real camera hardware remain deployment checks. See [SECURITY_DASHBOARD.md](SECURITY_DASHBOARD.md).

## Security account opening regression fix (2026-10-15)

The new Security dashboard incorrectly required `users.status='approved'`. Existing authentication treats staff as authorized through their database role, active/verified flags and session version; `users.status` is the resident approval field. The installed database has three active verified Security accounts retaining its default `pending` value, explaining the dashboard access denial after successful login.

Removed that resident-only approval requirement from the dashboard service and API without updating any real accounts, passwords, verification flags or permissions. Inactive/unverified users, wrong roles, forged actor IDs and revoked sessions remain rejected. The removed vehicle/visitor/parking management pages remain blocked for Security.

Validation: 33 Security backend checks now include actual password authentication and dashboard access for a legacy `pending` staff fixture, with independent inactive/unverified/revoked-session checks. The authorization suite passed 257 checks, including actual dashboard/API requests with that resident approval field left `pending`. The Security UI fixture also uses `pending` staff and passed 81 shared page/asset/CSRF checks. Chrome dashboard/theme/refresh/permission checks passed against this legacy-status fixture. No real login credentials or provider calls were used.


## Archive Management and account exclusion ? 2026-10-16

Implemented Super Admin Archive with confirmed vacancy, tenant departure, staff removal, private historical views, safe restoration and global archived-account exclusions. Legal ownership and original debts survive physical vacancy. Existing active visitor/parking sessions require real checkout; revoked authorization is separate from historical workflow/payment status. See [ARCHIVE_MANAGEMENT.md](ARCHIVE_MANAGEMENT.md) for the complete file inventory, schema, operational flow and test results.

Schema `2026.10.16.1` was applied locally twice after the private SQL/file backup at `C:/xampp/condo_backups/account_archive/snapshot_20261015_182300_b49d6c87`. Exact comparisons preserved existing account access/passwords/unit links, monetary values/payers/PayMongo results and historical row counts. Two legacy relationship records were imported; zero real archive events were created. HTTP-inclusive preflight passed with zero failures and warnings. Automated archive checks: 79 backend, 69 browser, 265 UI routes/assets/CSRF, alongside authentication, billing, notifications, lifecycle, parking, analytics, dashboards, Digital IDs, filed reports and deployment regressions listed in the implementation guide. Tests used disposable data and no real providers. Local PHP was 8.2.12; the exact 8.3 runtime and hosting deployment were unavailable.
