# THE CELANDINE - HOSTINGER PRE-DEPLOYMENT QA REPORT

Audit: **2026-10-16**. Testing domain: **https://thecelandinesystem.online**.

## A. Executive summary

**Verdict: NOT READY.** The workspace passes all **29 isolated PHP regression suites on PHP 8.3.35**, **347 syntax checks**, local preflight, and **1,358 browser layout combinations**. However, the hosted site serves a different/older release, uses a broken localhost logo URL, and still needs authenticated hosting, SMTP, signed sandbox payment callback and physical-camera verification.

No deployment, hosting setting change, working-database migration, real payment initiation or test email/SMS delivery was performed. Regression account/payment/migration mutations used generated disposable databases with provider credentials suppressed. Actual `.env`, branding, palette and existing layouts were preserved. Pending checks below are release gates, not claimed successes.

## B. Issue register

The initial register was recorded before changes: H-01 sandbox preflight conflict; H-02 PHP target mismatch; M-01 stale deployment guidance; H-03 missing hosting/provider access; L-01 asset/package inspection limits. Further findings were added from test evidence.

| ID | Severity | Root cause / affected module | Fix or required action | Verification / state |
|---|---|---|---|---|
| H-01 | High | Hardened preflight demanded live PayMongo keys even for testing. | Added explicit `--testing`, requiring production security settings and sandbox keys; retained separate live `--production` policy. Conflicting flags rejected. | Fixed; deployment suite 34 checks. No credentials changed. |
| H-02 | High | Requested PHP 8.3 target was untested locally; existing production gate required 8.4. | Obtained official checksum-verified PHP 8.3.35 in a temporary directory. Testing accepts patched 8.3+; live gate retains 8.4+. | All PHP suites/lint ran on 8.3.35. Actual hosted web/CLI version pending. XAMPP unchanged. |
| H-03 | High, unverified | No hosting administration access, designated test accounts/mailbox or actual signed sandbox callback. | Complete hosting acceptance below. | Public HTTPS/security probes completed; authenticated/provider checks pending. |
| H-04 | High | Legacy visitor/permit table upgrade omitted `unit_number_snapshot`, used by new requests. | Add nullable column idempotently under existing migration guard; preserve historical values. | Fixed; property pass 90 checks, visitor identity 88 and resident workflow suites pass. |
| H-05 | High | Restore validator passed client `DELIMITER` directives to mysqli; restricted restoration rejected old-account trigger definers. | Restore exact verified bytes through compatible SQL client in binary noninteractive mode. Explicit `--rebind-trigger-definers` changes recognized trigger ownership headers only in the disposable stream. Original snapshot untouched; no privileged fallback. | Fixed for tested canonical dumps. Backup suite 83 checks, including command/global/cross-DB rejection, original hash preservation and multiline SQL literal preservation. Other DEFINER formats still require review. |
| H-06 | High | Archive migration's nullable unit-label backfill changed maintenance `updated_at`; validator did not model exact intended nullable-label backfills. | Preserve historical modification timestamps. Permit only precomputed exact nullable unit labels and existing owner-link/version plans in the preservation check. | Fixed; restore test uses an old timestamp and protected-row hashes. Backup/Archive/deployment suites pass. Amounts, payers and statuses remain protected. |
| H-07 | High | Hosted `homepage.css` differs from workspace; current intro JS, resident dashboard CSS and Archive JS returned 404. | Upload one consistent tested release/dependency tree to the confirmed document root. | **Open hosting blocker.** No upload performed. |
| H-08 | High | Hosted login/signup logo requests `http://localhost/CondoSystem3/celandineorange.svg`. | Correct deployed canonical URL and inspect environment overrides. Root deployment URL must be `https://thecelandinesystem.online`. | **Open hosting blocker.** Broken/mixed-origin image confirmed by Chrome and screenshot. |
| M-01 | Medium | Guide had old schema, obsolete tenant payment policy and insufficient multipart guidance. | Correct schema 2026.10.16.1, tenant-owned booking/parking payments, sandbox profile, ZIP and combined-upload requirements. | Documentation fixed; tenant billing 203 checks passes. `.env.example` comments only. |
| M-02 | Medium | Staff activity catalog omitted legacy parking-slot `update`. | Include existing action without expanding private detail permissions. | Fixed; audit suite 133 checks. |
| M-03 | Medium / investigation | Headless hosted homepage received browser challenge/403; direct verified HTTPS probes returned homepage normally. | Verify ordinary browser and genuine webhook behavior; do not disable protection globally. | Pending; challenge screenshot saved. Not classified as a PHP homepage failure. |
| L-01 | Low / limitation | Most references are dynamic; literal scan cannot certify every uploaded asset or Linux path. | Combine exact-case scan and authenticated fixture resource checks; repeat on hosting. | Local coverage passes; actual deployed uploads/Linux filesystem pending. |
| T-01 | Test maintenance | Older fixtures lacked current tables, used unconfirmed legacy removal, expected bill rejection during Archive, or queried obsolete tabs/selectors. | Initialize fixture schemas; exercise confirmed Archive with financial preservation; update History/analytics/Archive selectors and native Escape sequence. | Relevant suites and 35 browser interactions pass. No security/business policy weakened. |

No unresolved critical code defect was confirmed in the tested paths. This audit is not exhaustive penetration testing.

## C. Repository and module verification matrix

Actual application: PHP/MySQL monolith with `config.php`, `includes/environment.php`, shared `includes`, `api`, public entry/recovery/verification pages and six role directories. Tenant/authorized occupant are resident account types. Dependencies include PHPMailer, Dompdf, local GSAP, QR libraries and shared SVG icons. Existing PayMongo handlers, authenticated document/export endpoints, private storage, `.htaccess`, migration/backup/preflight scripts and notification/retention jobs were inspected. Initial inventory: 274 PHP, 72 JS/CJS, 40 CSS files outside dependencies/private data; new audit JS brings syntax coverage to 73 JS/CJS.

| Module | Inspection and local evidence | Hostinger evidence / outstanding work | Result |
|---|---|---|---|
| Homepage/intro/navigation | 135 homepage + 44 intro checks: skip, once/tab, reduced motion, missing GSAP/script fallback | HTTPS routing observed; different release and browser challenge | Local pass; host pending |
| Authentication/recovery/verification | Authentication 67; authorization/lifecycle 285; actual fixture browser login | Login reachable; protected guest pages redirect; secure cookie flags verified. Real accounts/recovery mail/session persistence pending | Local pass; partial public host checks |
| Admin/Super Admin dashboards | Management totals/scope/read-only suite 52; both roles in layout sweep | No authenticated hosting access | Local pass; host pending |
| Resident/Tenant/announcements | Resident workflows 318; tenant policy 212; approved/pending/dashboard layouts | Current dashboard CSS 404; real role acceptance pending | Local pass; host blocked |
| Treasurer | Dashboard money/date/aging/permission suite 38; billing suites | No hosted account | Local pass; host pending |
| Security/QR/Digital ID | Dashboard 33; QR history 81; Digital ID 121; scanner browser interactions | HTTPS observed; real mobile camera/permissions/decoding pending | Local pass; hardware/host pending |
| Maintenance | Request/evidence states in workflow/Archive suites; restored historical timestamps preserved | Real storage/upload and maintenance account pending | Local pass; host pending |
| Archive/tenant lifecycle | Archive 89; role/lifecycle 285; paid/unpaid and active-parking preservation | Current Archive JS/API not shown to be deployed | Local pass; host blocked |
| Billing/receipts/PayMongo | Payment workflows 72; tenant billing 203; bank checkout extraction fixtures; pool/billing 139 | Webhook GET/HEAD 405 only; no genuine sandbox paid callback | Local pass; **Pending Live Sandbox Verification** |
| Parking/vehicles/stickers | Fees 123; central rates 67; v2 lifecycle 47; vehicles/violations 72 | Protected hosted movement/payment/slot workflows pending | Local pass; host pending |
| Visitors/private ID/permits | Identity 88; property pass 90; multipart fixtures; browser permit submission | Private directory denied; actual upload/authorized retrieval pending | Local pass; host pending |
| Community reports/timeline | 125 checks: scope, conflicts, anonymous credentials/evidence; layouts | Hosted reviewer/case/evidence acceptance pending | Local pass; host pending |
| Job Reports | 136: confirmation/snapshots/immutability/review/exports; layouts | Actual hosting trigger/font/ZIP/export verification pending | Local pass; host pending |
| Audit Logs | 133: dates/filters/paging/redaction/PDF/PNG/integrity | Hosted authorized downloads pending | Local pass; host pending |
| Analytics | 149: periods/calculations/scope/drilldown/exports/integrity; layouts | Actual host data reconciliation/exports pending | Local pass; host pending |
| Email/in-app notices | Outbox 58, mocked delivery; branding/email/documents 21 | No designated mailbox or worker access; real SMTP/cron pending | Queue logic pass; delivery unverified |
| Profile photos/private media | 99 upload/role/privacy checks; dynamic fixture images | Actual hosting private storage and uploads pending | Local pass; host pending |
| Migration/schema/backup | Deployment 34; backup 83; schema 2026.10.16.1 and InnoDB/index checks; preflight 0 failures/0 warnings | Actual DB/permissions/backup unavailable | Local pass; real backup/host verification required |

## D. UI, assets, JavaScript and dependencies

**Completed browser layout sweep:** 97 role/page cases x **360, 390, 430, 768, 1024, 1366, 1920px** x Dark/Light = **1,358 passing combinations**. Chrome checked document overflow, visible main content, theme state, one GSAP import, broken/mixed-origin imagery, exceptions and local 404/500 resources. Reduced motion stabilized layout measurement; wider landscape/tablet/desktop and portrait mobile dimensions were represented. This is not physical-device testing.

Captured **48 local screenshots**; selected Admin desktop-dark and mobile-light Super Admin, Resident, Tenant, Security, Treasurer and Maintenance dashboards were visually inspected, plus hosted login/challenge images. Every case was not reviewed individually by eye; not every hidden modal was opened in the sweep.

**35 workflow interactions pass:** sidebar/keyboard focus, booking creation and confirmation/cancellation into History, scanner tabs, 320px dialog fit, reduced motion/blocked GSAP, signup labels, invalid/successful login, profile/pending layouts, analytics action, Archive focus/Escape, announcement modal, gallery and permit submission/confirmation. Loading suite **308** checks passes. Homepage **135** and silent intro **44** pass. Public localhost tests used XAMPP; authenticated UI fixture used PHP 8.3.

An auxiliary older theme suite was only partially completed: selected public/resident/admin/scanner routes passed, but later navigation timed out. A stale temporary palette baseline caused an initial false comparison failure. These runs are **not counted as full passes**. Main completed layout sweep covers both themes. No CSS, images or palette were changed. Initial large concurrent browser runs timed out; layout coverage was resumed and completed, and timing-sensitive loading checks passed on rerun. This does not establish production load capacity.

Static asset scan: **305 application source files**, **54 literal references**, **0 missing / 0 exact-case mismatches**, **0 packaged images above 2 MB**, **265 dynamic references** requiring runtime context. Fixture resource checks cover representative actual pages/uploads, not every real database file. A Windows directory-entry check is not a substitute for actual Linux delivery.

Syntax: **274 PHP + 73 JS/CJS = 347 files, 0 failures**. Composer validation passes with only license metadata warning; PHP 8.3.35 platform requirements pass. Locked dependency audit returned no advisories or abandoned packages at audit time.

Hosted public sweep: **42 flagged cases**, representing repeated challenge, localhost-logo and old-theme/release behavior, not 42 independent bugs. Those results do not sign off the current workspace on hosting.

## E. Payments, visitor parking and email

Preserved centralized versioned pricing: first 15 minutes free, PHP 50 initial three hours, PHP 25 per succeeding started hour, qualifying PHP 300 overnight (22:00-06:00 Manila), no flat overnight fee before 06:00, later-hour charging and approval beyond 24 hours. Late overnight arrivals retain configured review policy. Legacy active sessions retain their frozen original version/rate; legacy hourly fixture tests do not replace the current tiered policy. Grace/overnight/multiday boundaries, confirmed entry/exit, occupied slots, free/no-bill exits, rate review and duplicate billing were tested. No rate setting changed.

Payment fixtures check signatures/tolerance, altered or invalid payloads, matching amount/currency/account/checkout, duplicate events and frozen invoices. Success redirects alone do not settle bills. Tenants pay their own requested bookings/parking and receive only their authorized receipts. No external checkout, real charge or genuine sandbox callback was performed.

**Pending Live Sandbox Verification:** use test keys and matching webhook secret; register `https://thecelandinesystem.online/webhooks/paymongo_webhook.php`; complete an authorized sandbox payment; observe a valid signed callback and exactly one correct settlement/receipt; replay the event to verify idempotency. Verify invalid signatures and mismatched amounts/currency remain rejected. Check genuine callbacks are not intercepted by hosting browser challenges without disabling site-wide protections. GET/HEAD 405 is method-gating evidence only.

Email tests use mocked delivery. Configuration presence, queue insertion and HTTP success do not prove inbox delivery. With a designated test mailbox and test-only accounts, check verification/recovery, approval/rejection, bill generation, payment receipt, announcements, visitor/parking decisions, reminders and other supported notices. Confirm archived recipients are skipped. Check worker heartbeat, outbox result, SMTP acceptance, inbox/spam and provider SPF/DKIM/DMARC. Record timestamps/references, never credentials. No real test recipient was selected or emailed by this audit.

## F. Security and hosting observations

Observed DNS/domain and certificate validation succeeded; HTTP root redirected to HTTPS. Guest resident/Super Admin pages redirected to login; guest dashboard API returned 401. Probes for `.env`, Git metadata, SQL baseline, configuration, Composer metadata, scripts, vendor source, private uploads and audit inventory returned 403. Session cookies included **Secure, HttpOnly, SameSite=Lax**; values were not recorded. This mixed public/private repository requires its nested HTTP restrictions.

Inspected/tested in exercised paths: prepared queries/allowlisted filters, contextual escaping, state-change CSRF, role/account gates, session rotation/revocation, ownership/IDOR restrictions, private document/report/receipt/QR access, MIME/size/decode upload validation, webhook signatures and server-controlled movement/payment state. Production disables runtime schema mutation; error display is disabled and reference IDs are used. Hosting logs/session files must remain private. No active face-recognition login path was identified or modified.

Limited current-source credential-pattern scan found no candidates; the scanned long PayMongo key pattern had no Git history match. This cannot certify all historical credentials. Existing documentation records prior exposure risk: owner must confirm revocation/rotation of any previously exposed credentials without publishing replacements.

Actual hPanel PHP/database/extensions, storage permissions, session path, outbound SMTP/HTTPS, provider setup and cron were unavailable. Public pages/cookies cannot verify those facts. Do not upload public `phpinfo`, credentials, SQL or test/debug scripts for troubleshooting.

## G. Database and integrity

Local preflight confirms schema **2026.10.16.1**, required columns/tables, critical unique indexes and InnoDB. Trusted snapshot rehearsal restores under a generated database-only account, migrates the copy, checks protected pre-existing columns, and removes its database/account. Tests cover binary values, repeated migration, wrong identity/privilege/amount changes, exact owner links/session revocation, original backup integrity and historical timestamps. Only precisely planned nullable unit-label backfills are additionally allowed.

`--rebind-trigger-definers` changes only recognized canonical trigger ownership headers in a disposable rehearsal stream after checksum verification. It does not authorize rewriting a real backup or removing triggers. Unknown DEFINER forms/views and routines/events require separate review. The backup command omits routines/events; establish whether the real installation uses them. Actual destination trigger ownership must match the reviewed migration account. No privileged restore fallback exists.

Working database observations were not write-locked: row counts stayed users 11, payments 35, bill items 100, parking requests 8, slots 800, vehicles 1, sticker orders 7, Archive events 3 and Job Reports 1. Seven table hashes remained identical. Users/payments hashes changed and audit rows grew **876 -> 888** during the audit window; recent audit metadata includes authentication, visitor movement and gateway confirmation. The initial hash-only baseline cannot attribute individual changes or certify a frozen database. No working-database settlement/Archive command was executed by this audit. Obtain a **fresh consistent backup in an agreed maintenance window**; do not restore the older baseline over newer activity. Evidence is saved in the protected before/after/observation JSON files.

No real migration was applied. Additive schema fixes take effect only in the next explicit, backed-up migration.

## H. Modified and created files

| Modified source/document | Purpose |
|---|---|
| `includes/resident_services.php` | Idempotent legacy unit snapshot column. |
| `includes/job_report_activity.php` | Legacy parking activity compatibility. |
| `includes/account_lifecycle.php` | Preserve historical timestamps during unit-label migration. |
| `scripts/preflight.php` | Hardened sandbox profile separate from live checks. |
| `scripts/validate_upgrade.php` | Restricted dump client, verified stream, explicit clone portability, exact preservation plans and redacted diagnostics. |
| `.env.example` | Comment-only sandbox/URL/client instructions; actual `.env` unchanged. |
| `DEPLOYMENT.md` | Correct PHP/extensions/upload/schema/payment/restore guidance. |
| `TENANT_CONFIGURATION.md` | Tenant responsibility for own bookings and parking. |
| `scripts/test_deployment.php` | Sandbox/live flag and key-mode checks. |
| `scripts/test_backup_restore.php` | Client command isolation, trigger portability/literals, protected history and timestamp regressions. |
| `scripts/test_payment_workflows.php` | Initialize required current fixture tables. |
| `scripts/test_pool_reservations.php` | Confirmed Archive and preserved booking debt/history. |
| `scripts/test_property_gate_pass.php` | Legacy nullable-column preservation and fixture visitor logs. |
| `scripts/test_visitor_parking_fees.php` | Confirmed Archive preserving active vehicle/billing. |
| `scripts/test_ui_browser.cjs` | Current History/analytics/Archive selectors and native keyboard sequence. |
| `inventory/ui-interaction-results.json` | Generated evidence of 35 passing interactions. |

Created: this report; `scripts/audit_hostinger_static.py`, `scripts/run_hostinger_regressions.py`, `scripts/audit_hostinger_browser.cjs`; protected `inventory/hostinger-*` evidence for inventory, official runtime metadata, syntax, static assets, public/guest/cookie/release probes, all regression logs/results, local/public browser metrics/screenshots and database observations. Raw files remain under HTTP-denied inventory. Temporary PHP/browser profiles are local test tools, not deployment dependencies. Do not deploy audit screenshots/logs as public assets.

## I. Controlled deployment and acceptance instructions

These are operator instructions, **not actions performed on Hostinger**. Resolve hosted release/URL issues and verify backups before any real migration.

1. **Confirm the real document root** for `thecelandinesystem.online` in hPanel, SSH/CLI access (or equivalent controlled migration process), actual database hostname/name/user, web/CLI PHP and available privileges. Do not assume another domain's `public_html` is correct. Use a separate testing database with sanitized/approved data.
2. **Back up hosted code + database + private uploads/report evidence/visitor IDs + configuration/signing keys together.** Stop writes/checkouts/workers for the final consistent snapshot. Store backups outside the web root. Verify manifest/checksum and restore on a separate controlled server before proceeding. Never import `database.sql` over an existing installation.
3. **Select patched PHP 8.3+ for web and CLI.** Enable `mysqli`, `mysqlnd`, `curl`, `fileinfo`, `mbstring`, `dom`, `openssl`, `session`, `gd` (JPEG/PNG/WebP/FreeType) and `zip`. Disable public errors; use private logs/session storage. Upload limit at least 10 MB; multipart limit greater than combined evidence cap (default 30 MB; use at least 32 MB). Confirm memory/disk/execution resources against actual guarded exports; 256 MB is a starting allocation to validate, not an observed hosting fact.
4. **Prepare one consistent release** from the tested workspace. Run `composer install --no-dev --prefer-dist --optimize-autoloader`, `composer check-platform-reqs`, `composer audit --locked`. Preserve `vendor/.htaccess` after dependency installation. Exclude `.git`, local `.env`, virtual environments, debug tools, audit artifacts/backups and fixture data. Keep required CLI tools protected and preserve all root/nested access rules. Keep existing uploaded private documents; never replace them with local fixtures.
5. **Configure private settings** on hosting:

   ```dotenv
   CONDO_APP_ENV=production
   CONDO_APP_URL=https://thecelandinesystem.online
   CONDO_AUTO_MIGRATE=0
   ```

   Testing intentionally uses production security mode. Supply actual hPanel DB details, authorized SMTP/sender, **PayMongo test key**, matching test webhook secret, persistent signing keys and selected SMS settings if used. Configure writable visitor/report storage outside the document root. Process environment overrides `.env`; remove stale localhost overrides. Prefer a private external file selected by `CONDO_ENV_FILE` where hosting supports it; otherwise verify direct `.env` access is denied. Do not copy local secrets automatically or print them in shell arguments/reports.
6. **Rehearse a verified snapshot** with matching code and compatible mysql/mariadb client, on a controlled database server. If its canonical exported triggers use the old account, explicitly request clone-only rebinding:

   ```sh
   php scripts/validate_upgrade.php --backup=/private/snapshot/database.sql --rebind-trigger-definers
   ```

   This needs disposable DB/account provisioning privileges, often unavailable on shared hosting. Rehearse on a separate controlled server if needed, then verify actual hosting privileges separately. Provide the private rehearsal environment. Stop on unexpected protected-data changes or unsupported definers; do not grant SUPER or omit immutable-history triggers. Original snapshot remains unchanged. Real restoration must use reviewed destination trigger ownership.
7. **Deploy and migrate in the agreed maintenance window only after verified backup/restoration.** Use temporary migration credentials with required schema/index/trigger privileges against the confirmed testing database:

   ```sh
   php scripts/migrate.php --apply
   ```

   Verify version `2026.10.16.1`, then return to the restricted runtime DB account. DDL is not one rollbackable transaction: failure can leave partial additive changes. Never drop real tables to force success.
8. **Run on hosting with its actual PHP/private configuration:**

   ```sh
   php scripts/preflight.php --testing --http=https://thecelandinesystem.online
   ```

   Resolve every failure and warnings for enabled SMS/HTTP scheduling. Keep live `--production` separate; do not enable live keys for testing. Verify correct logo URLs, `/assets/js/celandine-intro.js`, `/assets/css/resident-dashboard.css`, `/assets/js/archive.js`, stylesheet versions and private-artifact denial responses. Recheck ordinary browser/WAF behavior.
9. **Use designated hosted test accounts:** Super Admin, Admin, approved owner, linked Tenant, pending/archived account, Security, Maintenance and Treasurer. Verify login/logout/recovery/email verification, direct URL denial, Archive/session revocation, tenant-private booking payment authority, visitor/vehicle/permit/parking approval and actual confirmed movement, notices, dashboard totals, reports/timeline, Job Report checkbox/immutability, audit dates and analytics. Exercise uploads/authorized retrieval, PDF/PNG/ZIP downloads, all seven widths and both themes, open modal/dropdown/chart states and physical mobile camera over HTTPS. Record expected/actual results.
10. **Complete SMTP and real sandbox acceptance from section E** using a designated mailbox and authorized provider test flow. GET 405, reachable endpoint, success redirect, mock payment or configured SMTP does not prove end-to-end delivery/settlement. Confirm callback WAF handling and duplicate-event safety. No real charges/live keys.
11. **Configure jobs only after checking the test queue has authorized recipients.** Use actual PHP and absolute script paths; private cron logs. Existing commands:

    ```sh
    php scripts/process_notifications.php --limit=25 --seconds=45
    php cron/send_due_reminders.php
    php scripts/purge_visitor_ids.php --apply
    php scripts/purge_report_evidence.php --apply
    ```

    Worker every minute; due generation daily if required. Retention jobs daily only after approved retention/backups; preview without `--apply` first. These commands can send notices/delete expired evidence and were not run on real data by this audit. Verify heartbeat/outbox, logs, timezones and overlapping-job controls before reopening. Never put bearer secrets in URLs.

Official sources: [PHP support schedule](https://www.php.net/supported-versions.php), [Hostinger PHP selection](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/), [Hostinger extensions/configuration](https://www.hostinger.com/support/which-php-extensions-and-configuration-options-are-supported-at-hostinger/), [Hostinger plan limits](https://support.hostinger.com/en/articles/6976044-parameters-and-limits-of-hosting-plans), [MariaDB binary-mode client behavior](https://mariadb.com/docs/server/clients-and-utilities/mariadb-client/mariadb-command-line-client). Confirm actual account/plan settings; generic documentation is not proof of availability.

## J. Rollback and final gates

**Before reopening writes:** keep maintenance active, preserve logs/provider events, stop workers, and restore the verified matching **database + code + uploads/evidence + configuration/signing keys** to confirmed destinations. Use controlled operator tooling; never run fixture cleanup/drop commands against real databases. Recheck schema, private access, login, paid balances, QR and assets before reopening. Additive DDL has no automatic rollback command.

**After new writes, visits, notices or payments:** freeze access and take a new safety snapshot first. Reconcile settlements, callbacks, invoices and gate movements before restoration. Blindly restoring an older DB can erase newer records. Preserve idempotency/event history; do not mark paid from a redirect. Reopen after authorized reconciliation and smoke checks.

Pending release gates: consistent current hosted release; corrected canonical URLs; actual hPanel runtime/extensions/DB/storage/privileges; fresh real backup and restore verification; hosted role acceptance; designated-mailbox delivery/worker scheduling; **Pending Live Sandbox Verification**; real camera, uploads/exports and normal-browser/WAF checks. Reassess after those pass. **Current verdict: NOT READY.**
