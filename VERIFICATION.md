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
