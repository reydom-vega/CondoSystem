# Archive Management

Implemented for schema release `2026.10.16.1`. Archive Management uses the existing users, permissions, dashboards, financial records, visitor/parking workflows, audit log and staff Job Reports.

## Using the workflow

Only Super Admin can open **Archive** or perform archive/restore actions. Other roles cannot call the archive API, even with a forged URL or session role.

1. In **Units**, choose **Mark vacant**. Review the named accounts, unit, vehicles, assigned slots, active visits, unused requests, open maintenance and outstanding balance. Select a reason, explain it, and confirm the impact. Physical vacancy ends occupancy; legitimate owner identity, ownership relationship, dues responsibility and contractual owner parking remain.
2. In **Residents**, choose **End occupancy** for a tenant/occupant. Only that relationship ends. **End account relationship** explicitly closes the selected owner's system relationship and linked household access. Any separately recorded active entitlement survives.
3. In **Staff Management**, choose **Remove & Archive**. The confirmation identifies the staff member, role, email, joining date, filed reports and recorded pending responsibilities. A reason and confirmation are mandatory. Clicking **Remove & Archive Staff** then opens the shared **Confirm Staff Removal** dialog with the selected name/ID; Cancel preserves the form, and only final confirmation submits removal. All Super Admin accounts and self-removal are protected from this ordinary workflow.
4. Open **Archive → Residents / Tenants / Staff**. Search identity, account/staff/resident ID, email, former unit or historical plate. Combine date, reason, former role, unit and actor filters. Lists and historical sections paginate on the server in groups of 25.
5. Open **View archive** for read-only relationship periods, vehicles, original parking assignments, stickers, visitors/permits, maintenance, bookings, conversations, payments, violations, filed staff reports and audit history. Only populated sections appear. Confidential community case bodies, visitor identity documents, passwords and credential tokens are excluded; their original access controls remain in force.
6. To restore, explicitly select the authorized unit or staff role, enter a reason and a strong new password, and confirm. Current occupants/ownership conflicts block restoration. A new period is recorded. Old sessions, reset links, Digital IDs, visitor passes, vehicles and stickers stay revoked. A retained non-occupying owner can use **Resume Owner Occupancy** after occupancy checks.

Pending maintenance and already-filed staff reports remain in their existing work/review queues. Relevant active management staff receive a queued generic follow-up alert; delivery failure cannot undo a committed archive. Community case reassignment continues through its existing authorized workflow.

## Access, occupancy and finance

- Account archive state is separate from resident approval, legal ownership, physical unit occupancy, vehicle registration decisions, actual parking presence and payment status.
- Archived accounts become inactive, their session version increments, remember-me/reset/verification challenges are revoked, Digital ID credentials are revoked and their stored password is replaced with an unusable random secret. Protected requests recheck database account state. Password recovery, verification and CLI staff provisioning cannot reactivate an archived identity.
- Active account lists/selectors exclude disabled/archived users. New billing creation rechecks eligibility under a lock. Routine notification enqueue and delivery both check account state; raw mail/SMS entry points check recipients as well. Queued unsent notices are suppressed, with internal cancellation records retained. Historical delivery records remain.
- Vacancy keeps legitimate owner dues payable. Automatic monthly generation skips unresolved ownership and multi-unit billing responsibility and displays a billing-review list. Duplicate ownership also blocks manipulated manual bills. Existing single-unit bills, original payers, unpaid balances, receipts and PayMongo results are preserved. No old debt is transferred to a new occupant. Authorized financial follow-up can settle an archived payer's existing obligation.
- Vehicle and sticker authorization have separate revocation fields; original approved/issued/paid statuses remain. A restored user must obtain new authorization. Original revocation dates/reasons remain intact through subsequent lifecycle operations.
- Empty departing-person parking assignments are released only when safe. An occupied resident slot requires explicit physical inspection confirmation. Contractual retained-owner parking, another valid user's assignment and actual active vehicle sessions are preserved.
- A visitor already inside remains checked in. Security/Admin receive a review alert. The original QR may identify that active visit for **exit only**; it cannot grant new entry. The actual checkout uses the existing visitor/parking billing flow and original responsible payer. No checkout time is fabricated.
- Archive/restore operations use database transactions, row/advisory locks, CSRF, prepared statements, safe output, stale-preview detection and idempotent confirmation keys. A failed audit/archive write rolls back coordinated changes. Archive pages and API responses are private and not cached.

The project has one staff role per user; this release preserves that role architecture rather than inventing a parallel role system. No biometric or face-login implementation was found. Existing legacy occupancy start dates were not recorded and remain **Not recorded**. Conflicting legacy owners are not selected by row order. Unit snapshots preserve the unit known at migration/creation/departure; deleted pre-existing history cannot be reconstructed from current account fields.

## Database upgrade

The necessary additive schema is documented in `migrations/2026_10_16_account_archive.sql` and included in `database.sql`. Use the existing CLI migrator, which checks columns before adding them and backfills eligible legacy relationships idempotently. Do not repeatedly import the raw ALTER statements.

New tables:

- `account_unit_relationships`: original user/unit references, owner/tenant/occupant kind, owner ID, recorded/start times, separate occupancy and relationship end times, actor/reason, unique active relationship/ownership constraints.
- `account_archive_events`: original account and optional relationship IDs, category, lifecycle action, former role/unit, reason, actor snapshot, UTC timestamp, unique request key and a safe impact snapshot. Related business records remain in their original tables.

New columns:

- `users`: `archived_at`, `archived_by`, `archive_reason`.
- `vehicles`: authorization revocation date/reason and `unit_number_snapshot`.
- `parking_sticker_orders`: authorization revocation date/reason.
- `parking_sticker_vehicles`: `revoked_at`, `revocation_reason`.
- `payments`, `maintenance_requests`, `resident_service_requests`: `unit_number_snapshot`.

Deployment validates the new fields, InnoDB engines, unique keys and archive/reporting index column order. Archive and relationship times are written explicitly in UTC; date filters/display use Asia/Manila. Original module timestamps retain their existing time basis.

For another installation, back up its database and private uploads outside the web root, then run:

```powershell
C:/xampp/php/php.exe scripts/backup.php --directory=C:/xampp/condo_backups/account_archive
C:/xampp/php/php.exe scripts/migrate.php --apply
C:/xampp/php/php.exe scripts/preflight.php --http=http://localhost/CondoSystem3
```

Adjust paths/URL on hosting. This task upgraded localhost only; no hosted deployment was performed.

Local backup: `C:/xampp/condo_backups/account_archive/snapshot_20261015_182300_b49d6c87` (SQL dump, private files, checksum manifest and private before-upgrade state). The database has no stored routines/events. Migration was applied twice. Comparisons verified unchanged existing account access, passwords, unit links, payment amounts/statuses/payers/gateway results and historical row counts. Two eligible legacy relationship periods were imported. No real account was archived or restored; the local archive event table remained empty.

## Verification

All business tests use disposable databases and fictional accounts. Provider delivery is mocked; no real email, SMS or PayMongo request was sent.

| Automated suite | Passed checks |
| --- | ---: |
| Account archive backend, financial preservation, revocation, rollback, restore conflicts, ambiguity, pagination | 79 |
| Archive browser: role/CSRF guards, real confirmation/cancel/archive/restore, mobile and both themes | 69 |
| Focused final staff confirmation: named popup, centered placement, active before acceptance, Cancel, actual mouse-click removal and revoked access | 11 |
| Archive-focused shared UI routes, assets and CSRF | 265 |
| Authentication | 67 |
| Notification outbox | 58 |
| Tenant policy and lifecycle | 212 |
| Tenant billing | 203 |
| Resident workflows | 303 |
| Authorization and lifecycle | 257 |
| Digital Condo ID | 121 |
| Management dashboard | 52 |
| System analytics | 149 |
| Filed staff Job Reports | 136 |
| Visitor parking v2 | 47 |
| Fresh deployment, repeat migration and CLI provisioning | 29 |

Read-only local deployment preflight, including Apache protected-artifact checks, passed with **zero failures and zero warnings**. All 70 changed/new PHP and JavaScript files passed syntax checks; Git whitespace checks passed. Desktop and mobile screenshots were visually inspected. The browser suite covers 1366/768/390/320-pixel widths and the existing dark/light palette. The exact PHP 8.3 runtime was unavailable: tests ran on local XAMPP PHP **8.2.12**. External provider delivery, real camera hardware and Hostinger deployment were not exercised.

Useful repeat checks:

```powershell
C:/xampp/php/php.exe scripts/test_account_archive.php
C:/xampp/php/php.exe scripts/test_deployment.php
C:/xampp/php/php.exe scripts/test_ui_redesign.php --preview --archive-only
# While the last command is running, use its printed fixture base URL:
node scripts/test_account_archive_browser.cjs http://127.0.0.1:PORT/CondoSystem3/
# To run only the final staff-confirmation regression, add --staff-only to the node command.
# Press Enter in the fixture terminal afterward to stop it and remove its test database.
```

Archive confirmation labels use the existing `checkbox` class and restoration labels use `field-label`, so the legacy global label-hiding rule cannot hide required controls. Browser regression checks verify that the confirmation is visible, removal waits for it, an actual mouse click checks it, and an actual click on **Remove & Archive Staff** archives the disposable staff account and denies its subsequent protected requests. Restoration labels are also checked for visibility.

Earlier regression assumptions that unconfirmed vacancy erased owner links were updated to assert the new confirmed archive workflow and retained ownership. Inactive notification tests now expect suppression at enqueue, plus delivery-time eligibility checks.

## Files created or modified

The following inventory covers this implementation. Unrelated user assets under `assets/audio/` were preserved and are not part of the change.

### Lifecycle, authorization and delivery backend

- `api/account_archive.php`
- `api/admin_messages.php`
- `config.php`
- `create_admin.php`
- `includes/access_scanner.php`
- `includes/account_lifecycle.php`
- `includes/analytics_management.php`
- `includes/analytics_operations.php`
- `includes/archive.php`
- `includes/archive_ui.php`
- `includes/audit_report.php`
- `includes/authentication.php`
- `includes/authorization.php`
- `includes/billing.php`
- `includes/community_reports.php`
- `includes/deployment_schema.php`
- `includes/digital_id.php`
- `includes/job_report_activity.php`
- `includes/job_report_workflow.php`
- `includes/management_dashboard.php`
- `includes/notification_outbox.php`
- `includes/notify.php`
- `includes/parking.php`
- `includes/parking_allocation.php`
- `includes/payment_receipts.php`
- `includes/paymongo.php`
- `includes/portal_ui.php`
- `includes/remember_me.php`
- `includes/report_page.php`
- `includes/report_workflow.php`
- `includes/resident_accounts.php`
- `includes/resident_policy.php`
- `includes/resident_services.php`
- `includes/sms.php`
- `includes/staff_navigation.php`
- `includes/treasurer_dashboard.php`
- `includes/ui_icons.php`
- `includes/vehicle_violations.php`
- `includes/vehicles.php`
- `includes/violations.php`
- `includes/visitor_assisted_form.php`
- `includes/visitor_parking.php`
- `includes/visitor_parking_billing.php`
- `includes/visitor_parking_fees.php`
- `resend_verification.php`
- `verify.php`

### Existing and new portal pages

- `resident/maintenance.php`
- `resident/messages.php`
- `resident_service_pass.php`
- `superadmin/admin_messages.php`
- `superadmin/archive.php`
- `superadmin/generate_bills.php`
- `superadmin/maintenancerequests.php`
- `superadmin/pending_accounts.php`
- `superadmin/residents.php`
- `superadmin/staff.php`
- `superadmin/unitpayments.php`
- `superadmin/units.php`
- `superadmin/violations.php`

### Shared archive assets and database deployment

- `.gitignore`
- `assets/css/archive.css`
- `assets/js/archive.js`
- `database.sql`
- `migrations/2026_10_16_account_archive.sql`

### Automated checks and documentation

- `ARCHIVE_MANAGEMENT.md`
- `VERIFICATION.md`
- `scripts/migrate.php`
- `scripts/test_account_archive.php`
- `scripts/test_account_archive_browser.cjs`
- `scripts/test_authorization.php`
- `scripts/test_deployment.php`
- `scripts/test_management_dashboard.php`
- `scripts/test_notification_outbox.php`
- `scripts/test_tenant_policy.php`
- `scripts/test_ui_redesign.php`
- `scripts/ui_fixture_router.php`

Final staff confirmation verification: the focused 11-check browser run passed on the updated code and the 265 route/asset/CSRF checks passed. A broader browser attempt was interrupted by a transient DevTools navigation-context error after the staff workflow passed; navigation polling now retries only that transient inspection error. JavaScript syntax checks passed. No production staff accounts were removed.


### Registered vehicles when an account is archived

Full resident or tenant account archival includes every registered vehicle in the confirmation and archive snapshot, even when a vehicle records an older unit. Vehicle and sticker authorization is revoked without deleting registrations, ownership documents or payment history. Both Admin and Super Admin Parking Management lists exclude archived accounts and revoked vehicle authorizations. Restoring an account does not restore its old vehicle authorization.

Registered Vehicle History opens automatically in an archived account's details. Parking Management explains where that history is retained and offers an Archive shortcut to users who already have Archive permission. Ending only one unit relationship preserves vehicles associated with another active unit; physical vacancy that retains legal ownership keeps the existing owner parking policy.
