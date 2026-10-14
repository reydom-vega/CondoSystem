# Staff job reports

Staff can open **Job Report** from the shared sidebar. The shared `job_report.php` route serves each authenticated Super Admin, Admin, Maintenance, Treasurer or Security account's personal workspace. Residents and tenants have neither the menu nor backend access. Even a Super Admin's personal API is restricted to that account.

The personal workspace has two shared-style tabs: **Job Report** for the period, work activity and draft notes, followed by **My Filed Reports** for submitted versions, search, filters and downloads. Tab switching preserves unsaved form inputs without reloading; `?tab=filed` opens the list directly. Report notification links open the filed tab and existing detail modal. Preparing a returned report's correction switches back to the report form. Staff Management keeps its existing management tabs.

Daily reports default to today in `Asia/Manila`; monthly reports default to the current calendar month. Changing the date reloads the selected period. **Refresh report** refreshes recorded work without discarding notes. Save a draft, preview, confirm accuracy, then **File Report**. Preview also provides PDF and PNG downloads; downloading alone does not file a report. Multi-page PNG exports contain numbered 1240 × 1754 white-paper pages in a ZIP.

Super Admins review submitted versions under **Staff Management → Staff Reports**, the first/default tab. The existing Staff Information and Add Staff Account tabs, forms and deep links remain available. Search uses the filed staff ID/name snapshots, supports case-insensitive partial matching, and combines with role, report type, status, reporting day/month and submission-date filters. Date/month filters match reports overlapping the selected local period; submission dates use Philippine calendar dates. Counts reflect the current filters and count **versions**, including superseded returned versions. Clear removes all filters. Lists paginate at 20 versions; activity/preview pages at 50 events.

Opening a report never marks it Reviewed. A Super Admin explicitly marks it Reviewed or returns it with meaningful feedback (at least 10 non-space characters, maximum 4,000). Only the owner of the latest returned version can submit its correction. It creates a linked new version; the old snapshot and review history remain unchanged. Reviewed versions cannot be edited or reopened. A Super Admin cannot review their own report. Another actual Super Admin can review it; otherwise its status remains Submitted. Community-report oversight settings do not grant job-report review rights.

## Genuine activity sources and definitions

`includes/job_report_activity.php` reads sanitized fields directly from existing sources, without adding an activity ledger:

| Source | Reportable records | Time handling |
| --- | --- | --- |
| `audit_logs` | Whitelisted completed work actions and recorded rejections: staff/account changes, billing, bookings, maintenance, announcements, vehicles, violations, parking, service/identity decisions, report filing/review | Existing TIMESTAMP read through the application's +08:00 MySQL connection, converted to UTC for union ordering |
| `visitor_logs` | Actual visitor entry/exit attributed to `logged_by` / `checked_out_by` | Existing local DATETIME, converted from +08:00 |
| `qr_scan_logs` | Recorded structured visitor/parking/permit/digital-ID verifications and refusals with a known reference | Existing UTC event time |
| `report_events` | Actual management replies, internal-note actions, status/priority/assignment/escalation/conflict/code-revocation actions | Existing UTC event time |

Each reporting period is start-inclusive/end-exclusive. Original source timestamps are preserved and displayed in Philippine time. QR verification repeats for the same scanner, pass type/reference and outcome within 60 seconds of a previous matching scan are suppressed. Visitor movement is taken from visitor_logs, so its audit and QR movement copies are excluded. The aggregate combined parking-approval audit is excluded because individual decisions already exist. Duplicate controller `issue` / `create` entries within 60 seconds of native citation / bill creation are suppressed. No raw historical audit rows are altered.

- **Total Activities:** reportable recorded events, including recorded rejections/refused verifications.
- **Completed Actions:** finalized actions/decisions (including an explicitly recorded rejection) and successful QR verifications. Update events and refused QR verifications are excluded.
- **Updated Records:** update events, not a count of distinct records.
- **Filed Reports:** the owner's filed daily/monthly versions overlapping the selected reporting period.
- **My Work Summary:** role-specific counts derived from the same safe events. Security gets visitor entry/exit, QR verification and parking checkout counts; Maintenance gets requests updated/completed and status updates; Treasurer gets processed billing records, verified payments and bill updates; Admin gets reviewed requests, reservations and maintenance updates; Super Admin gets staff actions, approvals and system-management actions. No financial amounts or invented worker-assignment statistics are included.

Logins, page visits, document views, automatic reminders/parking monitoring, unauthenticated system events and unknown legacy `entity_type='0'` rows are excluded. Their original audit records remain available in Audit Log. Old generic maintenance `update` records remain generic: the system does not infer historical completions from a request's current state or private notes. Future successful maintenance transitions record their actual status action. Successful management message sends and bill-item additions now record missing server-side work actions. Failed operations create no success activity.

The six plain-text work-note fields are **Staff-Reported**, each capped at 4,000 Unicode characters. They never become verified system events. There is no new evidence uploader: existing module evidence stays in its existing protected workflow. Staff should not copy confidential resident/case information into notes. System descriptions come from fixed safe labels, never `audit_logs.details`, contacts, QR URLs/payloads/credentials, anonymous identities, case bodies or evidence paths. Restricted case events disclose only a generalized work description and event reference, without the case reference or reporter identity.

## Filing, security and notifications

`job_report_drafts` stores only editable own-period notes with optimistic revision checks. `job_reports` stores an immutable sanitized snapshot, existing staff ID/name/role snapshots, exclusive period end, reference, version, previous-version link and SHA-256 content hash. `job_report_reviews` preserves submission/review history. Database triggers reject snapshot changes/deletion, invalid status transitions and changes/deletion of review-history rows. Foreign keys preserve filed history when an account is deactivated. Staff account deletion is not introduced.

Filing locks the authenticated account, revalidates role/activity/account state, verifies the preview hash, and commits the snapshot, submission history, audit action and outbox jobs in one transaction. Unique own-period/version, correction-parent and request-key constraints plus payload matching prevent double clicks and correction forks. A changed preview must be generated again. Only the mutable review status changes after filing; hash-verified snapshot content remains immutable. Downloads contain the original snapshot plus the current explicitly recorded review history.

Personal vs management scope is checked server-side on every list, detail, review and export. Arbitrary submitted staff/user IDs never select a different personal actor. POST actions and exports require the existing workflow CSRF token. Responses use `Cache-Control: no-store, private`. Dompdf disables remote resources and PHP execution, uses existing local fonts and orange document branding. PNG export uses existing GD/ZIP, private randomized temporary archives, and unconditional cleanup. No reports are published under uploads or a public assets directory.

Submission queues generic email notices to active verified Super Admins. Review queues a generic notice to the owner; private notes and review feedback appear only after authenticated access. The shared notification bell includes submitted independent-review work and the owner's latest returned version. The existing notification worker delivers the queued jobs and checks recipient/role/relevance again. No new SMTP credentials, mail service or scheduled task is needed. Automated test delivery is mocked.

## Installation and deployment

1. Back up the database and private files with the existing backup script.
2. Run `php scripts/migrate.php --apply` (or `php scripts/migrate_job_reports.php --apply`). Migration **2026.10.14.4** creates three additive tables, source actor/time indexes and immutable-history triggers. Run it twice safely. Do not import database.sql over an existing installation.
3. Run `php scripts/preflight.php`. The runtime needs existing Dompdf/GD/fonts plus PHP ZIP for multi-page PNG downloads. The migration account needs CREATE TRIGGER privilege; the runtime account needs no schema privileges. Keep triggers in database backups/restores (the existing mysqldump backup includes them).
4. Keep the existing notification worker running. Add no duplicate worker.

Fresh installations import `database.sql`, then run the complete migration to install/validate triggers and record the version. The standalone migration SQL contains the additive table definitions; the PHP migration creates the portable compound trigger statements. `CONDO_JOB_REPORT_MAX_ACTIVITIES` defaults to **2000**, bounded to 100–5000. Filing/preview/export requires the complete period to fit this explicit cap; over-limit requests fail with instructions instead of truncating activities. Live activity history remains paginated above the cap. Use daily reports or deliberately raise the cap after assessing server resources.

Filed job reports are official records and are not silently purged by visitor-ID/evidence retention jobs. Protect backups and apply the organization's official records retention policy before introducing any explicit administrative archival/deletion mechanism.

## Verification

`php scripts/test_job_reports.php` uses a disposable database and mocked delivery, covering roles, actor/time boundaries, privacy, pagination, drafts, filing, idempotency, database immutability, review/correction versions, independent review, notification queueing and actual PDF/PNG/ZIP exports. Temporary fictional export samples are placed in the OS temp directory for visual inspection.

For browser tests run `php scripts/test_ui_redesign.php --jobs-only --preview`, then pass its printed loopback base URL to `node scripts/test_job_reports_browser.cjs <base-url>`. Press Enter in the fixture process afterward to remove its disposable database. Tests exercise personal roles, Staff Management tabs, drafts, preview/file/return/correction/review, authorized downloads, filters, mobile layouts and both themes. They do not send real email or call PayMongo. Review PDF renders and browser screenshots before deployment.

Personal-tab update verification: PHP/JavaScript syntax checks passed, along with 138 fixture route/asset/CSRF checks and 82 Chrome workflow checks. These include the two-tab order, retained notes and filters, browser Back, keyboard navigation, deep links, refresh persistence, correction navigation, existing filing/review/export behavior and mobile layouts in both themes. Desktop dark and mobile light filed-report screenshots were visually inspected.
