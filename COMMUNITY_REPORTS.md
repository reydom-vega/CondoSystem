# Private community reports

Residents, tenants, Admins and Super Admins use **Community Reports** in their existing sidebar. Security, Maintenance and Treasurer accounts have no case access. This module never creates fines, violations or payments.

The shared interface provides My Reports, a two-step form, identified report history, anonymous tracking, private case details, a chronological timeline, messages and evidence. Management can assign eligible reviewers, record internal findings, request information, change priority/status, escalate, record conflicts and close/reopen cases with explanations. Residents see only their own identified cases. Internal events and evidence are excluded by server queries from all reporter responses.

## Simplified reporting flow

Residents and tenants select **New Report**, enter the subject, category, incident date, location and description, then select **Review report**. Account reporting is selected by default; anonymous reporting is still available. Extra incident details and evidence are optional disclosures. Staff-related concerns still require the appropriate routing role, and Other categories/locations still require clarification. The review screen preserves the accuracy confirmation and provides **Edit details** before submission. After successful submission, the case explains that management will review it; no additional approval steps are required from the reporter.

Identified active reports appear in **My Reports** and completed ones in **History**. Anonymous cases still require their separate reference and private access code. A case now explains who needs to act next, including **Your reply is needed** when management requests information. Progress shows only actual recorded status changes, and the full case history is available in a disclosure.

Management opens a report and uses **Message reporter**, **Update status**, or **Private note**. A status-dependent shortcut prepares an authorized status change and focuses the explanation field; it never saves a change by itself. The status change and reporter-visible explanation are submitted together. Reviewer assignment, priority, escalation, conflicts and code revocation remain under **More case options**, with existing server checks. Assignment and these advanced actions are not prerequisites for an ordinary review. Private notes and internal evidence retain their existing visibility restrictions.

Search and status stay visible; other list filters are under **More filters**. The layout uses the existing theme variables, typography and orange accents. This presentation update changes no database schema, authorization rules, report lifecycle transitions or API contracts.

## Installation and settings

Back up before deployment. Import `database.sql` only on an empty installation, then run `php scripts/migrate.php --apply`. An otherwise current installation can run `php scripts/migrate_community_reports.php --apply`. Both are idempotent. The six additive tables separate cases, identified reporters, anonymous credentials, conflicts, unified timeline events and attachments. The schema version is `2026.10.14.2`. No existing business record is converted or duplicated.

| Setting | Default / purpose |
| --- | --- |
| `CONDO_REPORT_INDEPENDENT_REVIEWER_IDS` | Comma-separated existing approved Admin/Super Admin IDs explicitly designated for independent oversight; blank seals oversight cases. |
| `CONDO_REPORT_STORAGE` | Absolute path outside all public document roots. Blank uses `condo_private/<application hash>/reports` beside the webroot. |
| `CONDO_REPORT_MAX_FILES` | 5; configurable 1–10 per submission/update. |
| `CONDO_REPORT_MAX_FILE_MB` | 10; configurable 1–10 MB. |
| `CONDO_REPORT_MAX_TOTAL_MB` | 30; configurable 1–60 MB, subject to PHP limits. |
| `CONDO_REPORT_ANONYMOUS_ACCESS_DAYS` | 365; configurable 1–3650 days. |
| `CONDO_REPORT_EVIDENCE_RETENTION_DAYS` | 365 days after Closed, Declined or Withdrawn; configurable 1–3650. |
| `CONDO_REPORT_CATEGORIES` | JSON array of category labels; blank/default uses the supplied 16 categories. Other is always available. |
| `CONDO_REPORT_AUDIT_KEY` | Optional persistent 64-character hexadecimal key kept outside the DB. Otherwise migration seeds a random `report-audit` key in existing `access_signing_keys`. |

PHP needs mysqli, mbstring, fileinfo and GD with JPEG/PNG/WebP support. PHP `upload_max_filesize` must cover the per-file limit, `post_max_size` must exceed the combined limit plus form overhead, and `max_file_uploads` must cover the count. The default combined 30 MB fits the current 40 MB request limit. Images are restricted to 8 megapixels and 8192 pixels per dimension, then resampled to a maximum 2048-pixel edge. No new dependencies are needed.

## Property manager and conflicts

The configured property-manager Super Admin is the designated escalation reviewer. Administrative complaints are limited to independent Super Admins; Super Admin/unknown administrative complaints require explicit configured oversight. Mentioned known staff are matched on their username/full name and added to case conflicts. Reviewers can record further conflicts and strengthen restrictions. Recording a conflict clears assignment. An accused or conflicted person is excluded from detail, list, search, statistics, evidence, updates and notifications even after a role change. Another Super Admin does not automatically qualify for oversight.

If the property manager is accused, that account cannot review its own case. Without another explicitly configured, non-conflicted reviewer, the case stays sealed. Free-text matching cannot identify every nickname or unnamed accused person; reporters select staff scope and eligible independent reviewers must promptly record any additional conflict discovered. No confidential directory is exposed to reporters.

## Anonymous access and privacy

An anonymous case has no `report_identities` row. Reporter events have no actor user ID. Notifications are never sent to an anonymous reporter's account. Report references are identifiers, never authorization. A separate 256-bit random access code is shown once; only SHA-256 is stored. Tracking sends it by CSRF-protected POST, never in URLs or browser storage. A server-session capability lasts up to 30 minutes and is checked against the current credential, expiry and revocation on each access. Credential holders can renew it; eligible management can revoke it. Old codes and session grants stop working after renewal/revocation. Lost codes cannot be recovered by looking up an account. Explicit tracking-session termination removes session grants.

Submission requires an approved residential account; later anonymous tracking can run without login. Rate limits use the existing hashed-IP authentication bucket without a case/user association. Anonymous submission/action records do not call account-linked general audit or analytics functions. Hosting access logs, authentication records, session storage and operational metadata can still exist, so this is reviewer anonymity, not guaranteed technical anonymity. Reporter text and PDFs may voluntarily identify their author.

Evidence uses random filenames in outside-webroot storage. Extension, actual MIME, size and content checks apply on the server, plus browser validation. Images are decoded and re-encoded as JPEG to strip EXIF/trailing content. Anonymous filenames are replaced with generic Evidence labels. Plain PDFs undergo structural checks and active/encrypted-content rejection and are served only as downloads; this is not a full antivirus or PDF sanitization service. PDF text/metadata are retained, with an explicit privacy warning. Evidence responses require current case permissions or a valid anonymous grant, check content hashes, and disable caching. Original storage paths are never returned.

Every status change, public message, internal note, assignment, escalation and conflict is an append-only event. Original allegations are immutable through application APIs. HMAC-linked events bind the original submission, mutable case/credential state and evidence digests. Integrity failures stop case access. Runtime code exposes no history-edit/delete endpoint. A database administrator with access to the stored signing key can rewrite both data and signatures; an externally protected `CONDO_REPORT_AUDIT_KEY` strengthens separation. Protect the key and trusted backups; do not rotate it without an explicit historical-audit migration.

Notifications contain only a generic private-update message and reference. Existing outbox delivery checks current account/contact state and case eligibility before sending. Notification Delivery hides report jobs from conflicted/ineligible reviewers and prevents their retry. Anonymous reporters use tracking for updates. Existing email-provider acceptance rules still apply.

## Retention and backups

`php scripts/purge_report_evidence.php` is a dry run. After approving the retention policy, schedule `php scripts/purge_report_evidence.php --apply` daily. Only evidence of Closed, Declined or Withdrawn cases older than the configured closure interval is removed; open/reopened cases are retained. Each deletion batch records a signed internal retention event; metadata and case history remain. Expired anonymous codes automatically stop authorizing access. Case/identity/history retention requires a separate property-approved archival policy; this command deliberately does not destroy investigations or audit records.

`scripts/backup.php` includes report evidence as `community_report_evidence` beside the SQL snapshot and existing private uploads. Restore it to the configured private report path together with the matching DB, code and signing configuration. Prevent application writes while making a consistent DB/file backup. Protect, restrict and eventually expire old backups under the same property policy. `validate_upgrade.php` also verifies existing report records remain unchanged during upgrade, including composite-key tables.

## Verification

Run `php scripts/test_community_reports.php` for provider-free case isolation, anonymous privacy/credentials, workflow, internal-note boundaries, conflicts, integrity, notifications and validation. For actual browser/multipart checks, start `php scripts/test_ui_redesign.php --preview`, use its loopback URL with `node scripts/test_reports_browser.cjs <URL>`, then press Enter in the fixture process to clean up. Fixtures use disposable databases and test-only recipients; never run against live accounts. The browser suite exercises desktop/tablet/mobile themes, two-step submission, previews, duplicate prevention, spoofed uploads, evidence isolation, internal attachments, declined timelines and anonymous follow-up. Existing authorization, resident workflow, notification, tenant billing, deployment and backup regression suites remain applicable.

Simplified-flow verification: PHP and JavaScript syntax checks passed, along with 125 backend integration checks, 96 route/asset/CSRF checks and 120 browser workflow checks. Coverage includes both resident account types, Admin/Super Admin layouts, visible privacy controls at four viewport sizes in both themes, optional inputs, required staff routing, submission confirmation, replies, status updates, private notes, real progress history and anonymous code handling. Three additional read-only browser checks verified action labels and readable review-access wording. Desktop and mobile form, review and management screenshots were visually inspected. All records were confined to disposable test databases; no email or payment provider was contacted.

Local verification on October 14, 2026 passed 125 report integration checks, 93 browser checks, 96 focused report route/asset checks, 221 authorization checks, 292 resident workflow checks, 50 notification checks, 163 tenant billing checks and 27 deployment checks. Actual evidence backup copying and retention were tested with disposable files. All provider delivery was mocked; no test case was created in the working database. The full restricted-account restore suite could not complete because the existing local MySQL `mysql.db` permissions-table index is corrupt (error 1034). Database/schema preflight passed; the final main-site HTTP rerun was unavailable with Apache stopped. Apache startup was rejected by automatic approval review, so start Apache through XAMPP before opening the main localhost site.

Start a fresh preview fixture for each browser test run so the submission abuse limit remains meaningful. `--reports-only --preview` runs the focused report routes quickly. Preview cleanup reconnects if MySQL restarted while the fixture was open.
