# Deployment procedure

This release prepares the existing PHP/MySQL application for a controlled deployment. It does not provision hosting, rotate provider credentials, verify real payment callbacks or operate gate hardware. Complete those checks before enabling production traffic.

## Server requirements

Use an updated PHP 8.4 or later release for production, Apache 2.4, and MySQL/MariaDB with InnoDB. The application retains PHP 8.1 syntax compatibility for local development; production preflight requires PHP 8.4+. Check the [PHP support schedule](https://www.php.net/supported-versions.php) and install current security updates. Enable PHP `mysqli`, `mysqlnd`, `curl`, `fileinfo`, `mbstring`, `dom`, `openssl`, `session` and `gd` (JPEG/PNG/WebP support). Allow individual uploads up to 10 MB and multipart requests of at least 12 MB. Keep `display_errors` off and route error logs to private storage. Review dependencies with `composer audit --locked` in the deployment environment.

Apache must honor the repository's `.htaccess` files (`AllowOverride` must permit its authorization, rewrite and options directives). These files deny configuration, private documents, dependency source, tools, database exports and repository metadata. Apache describes both [authorization directives](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#require) and [how overrides are enabled](https://httpd.apache.org/docs/2.4/howto/htaccess.html). On another web server, install equivalent explicit denial rules; `.htaccess` is not interpreted by nginx or PHP's development server.

The application needs outbound HTTPS to PayMongo and the selected SMS provider, outbound SMTP, and an inbound HTTPS route for `webhooks/paymongo_webhook.php`. Use hosting that supports those operations and a migration CLI. InfinityFree's free-tier webhook limitations are covered in [INFINITYFREE_SETUP.md](INFINITYFREE_SETUP.md).

## Configuration and secrets

Use server environment variables, or copy `.env.example` to a private `.env` and fill it with new values. `CONDO_ENV_FILE` can point to a file outside the public directory. Process environment variables take precedence. The `.env` reader supports simple `CONDO_NAME=value` lines and optional surrounding quotes; it does not execute shell expressions or expand variables.

For production, set:

```text
CONDO_APP_ENV=production
CONDO_APP_URL=https://your-domain.example/CondoSystem3
CONDO_AUTO_MIGRATE=0
```

Set the real database, SMTP, sender, PayMongo secret/webhook secret and a persistent random `CONDO_PASS_SIGNING_KEY`. Use a dedicated database account. Grant schema privileges only to the account used for the migration; the runtime account should have only the read/write privileges needed for application tables. Production web requests do not perform schema changes, and require the migration version recorded by the CLI. All application instances must use the same canonical URL and signing key.

Generate a signing/HTTP reminder secret with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, then store it privately. Rotating the pass-signing key invalidates existing parking QR codes. Set the selected SMS provider credentials if phone verification/reminders are enabled. Set `CONDO_CRON_SECRET` only when HTTP scheduling is needed; keep it separate from payment and QR secrets.

Revoke and replace all SMTP, database and PayMongo credentials previously embedded in source, documentation or archives. Removing them from the current files does not revoke them or remove historical copies. `.gitignore` prevents new local settings and uploads from being added; it does not remove already tracked files.

Keep the recommended tenant defaults from `.env.example`:

```text
CONDO_TENANT_AMENITIES=1
CONDO_TENANT_VISITORS=1
CONDO_TENANT_PARKING=1
CONDO_TENANT_VEHICLES=1
CONDO_TENANT_MAINTENANCE=1
CONDO_TENANT_MESSAGES=1
CONDO_TENANT_PERMITS=1
```

These flags also apply to family/relative and friend occupants. Tenant billing stays read-only, and payments, receipts and new sticker orders remain owner-only regardless of flags. See [TENANT_CONFIGURATION.md](TENANT_CONFIGURATION.md) for the access matrix, ownership linking and occupancy procedures.

## First installation

1. Create an empty utf8mb4 database and import the `database.sql` baseline through your database administration tool. This is a fresh-install schema, not an upgrade script; never import it over an existing installation.
2. Install dependencies from `composer.lock` using `composer install --no-dev --prefer-dist --optimize-autoloader`, or upload the same installed `vendor` tree when Composer is unavailable on the server. Preserve `vendor/.htaccess` if Composer rebuilds that directory.
3. Configure private credentials and run `php scripts/migrate.php --apply`. This creates/updates all module storage and records `2026.10.14.3` only after table, column, critical unique-index and InnoDB checks pass. The command requires explicit `--apply`, serializes migrations using a database advisory lock, and can be repeated after resolving a failure. MySQL DDL is not rolled back as one transaction: a failed upgrade may have applied earlier steps.
4. Provision the initial superadmin using the CLI-only `create_admin.php` helper, then use Staff Management for subsequent staff accounts. Review `php create_admin.php --help` before supplying credentials. Use its `--password-stdin` input to keep passwords out of command arguments; updating an existing account requires explicit `--update`.
5. Import/review units and parking inventory, configure sticker price and visitor limits as superadmin, and approve resident accounts and vehicles through the UI. Confirm account types and approve the one unit owner before approving linked tenants/occupants. Default amenity hours/capacity and permit categories must match the property's operating rules.
6. Make `private_uploads` writable by the PHP worker, with no direct HTTP access. Back up uploads together with the database. Keep server session storage private and writable.

## Upgrade an existing installation

The global profile-photo release adds only nullable `users.profile_photo`. An otherwise current installation can run `php scripts/migrate_profile_photos.php --apply`; the full release migration includes the same idempotent change. Enable GD for the web PHP worker (Hostinger PHP Configuration ? Extensions ? gd; XAMPP `php.ini` ? `extension=gd`, then restart Apache). Protect and back up `private_uploads/profile_photos` with the database. Confirm direct HTTP access is denied and only the authenticated `profile_photo.php` endpoint serves images. See [PROFILE_UI.md](PROFILE_UI.md) for roles, private media rules and tests.

The Digital Condo ID release adds only hashed, short-lived identity credentials to `digital_id_credentials`. An otherwise current installation can use `php scripts/migrate_digital_id.php --apply`; the normal release migration also includes this table. See [DIGITAL_CONDO_ID.md](DIGITAL_CONDO_ID.md) for verification permissions, QR expiration, revocation, retention and tests. Production issuance requires the canonical HTTPS URL. Digital ID verification does not authorize physical entry or replace visitor and parking passes.

1. Announce a maintenance window and stop application writes, reminders and payment checkouts. Continue to preserve received provider events or arrange webhook retry through your provider configuration.
2. Take a consistent database backup plus `private_uploads`, existing configuration and the previous code release. Test restoration on a separate database. Keep signing keys with the protected backup so QR verification survives restoration. Do not store backups under the public application directory.
3. Deploy the code/dependencies to staging against a restored database first. Run the migration and readiness checks there; inspect existing future bookings, visitor reservations, vehicles, outstanding bills and historical sticker orders.
4. In the maintenance window, deploy the same code and run `php scripts/migrate.php --apply` with temporary migration credentials. Do not convert non-InnoDB production tables automatically: investigate and perform a separately backed-up engine migration if the command reports them.
5. Switch back to the runtime database account, set production mode and disable auto migration. Run `php scripts/preflight.php --production --http=https://your-domain.example/CondoSystem3`. The command reads configuration/schema and checks HTTP denial responses; it never changes business data, runs DDL or calls provider APIs. Warnings for SMS/HTTP reminders must be resolved if those features are enabled.
6. Review owner/occupant relationships before reopening access. Migration backfills `unit_owner_id` only when the normalized unit has exactly one active, verified, approved owner. Review blank legacy account types that retain owner compatibility and approved tenants left unlinked. Correct ambiguous tenants through End occupancy and reapproval after confirming the owner. Reconcile outstanding legacy invoices before occupancy changes; migration does not transfer their ownership or amounts.
7. Perform the smoke checks below, then resume writes and scheduling. Existing parking QR codes from the former signing implementation need regeneration. Historical attendee counts default to one and require operational reconciliation. Link earlier paid/issued sticker orders to eligible approved vehicles in the staff parking screen. Historical paid tenant orders require a valid current owner link before management can complete issuance.

## Operational smoke checks

Check one approved resident account and a denied resident account, and at least one account in every staff role. Restricted URLs must return denial even when entered directly; hiding navigation alone is insufficient.

Exercise registration, account approval, login/logout, password reset and session invalidation after role/deactivation changes. Register a vehicle with OR/CR, approve it, select it for a sticker order, pay it in the provider sandbox, verify the payment once and issue it once. Confirm a second account cannot open private documents or edit requests.

Approve a tenant against a confirmed unit owner and verify its **Unit Bills** view shows bill details without payment controls, receipts or provider data. Direct payment/checkout attempts must be denied. Register and approve the tenant's vehicle, then have its owner order/pay the sponsored sticker and management issue it. Confirm the owner cannot open the tenant's OR/CR and the tenant cannot open another occupant's requests or documents. Check a disabled tenant service through both navigation and a direct URL/API request. End one tenant's occupancy and verify its session/access is revoked while other occupants retain access; check that removing the owner revokes all linked occupants. Preserve and reconcile legacy invoices rather than treating occupancy changes as debt transfers.

Register a visitor with parking, approve the registration and assign an available visitor slot, verify the QR at the gate, check in and check out. Cancel/reject a parent registration and confirm its parking authorization disappears. Check one permit decision, amenity capacity/conflict, maintenance transition, violation dispute and itemized bill. Verify all resulting staff actions in the audit log.

For PayMongo, register the actual HTTPS webhook URL and newly generated matching secret in the provider dashboard. Exercise a signed paid event, duplicate event and invalid signature. Confirm amount/currency/ownership matching and provider retrieval from the resident return path. Do not use the browser success URL as payment proof. Switch to live keys only after a live-mode readiness check and an agreed operational payment verification.

Check scanner camera access on the deployed HTTPS site and the manual verification fallback. Check phone/email provider delivery using controlled test recipients. Review the rendered UI on desktop and mobile; DOM/syntax tests do not establish browser appearance or camera compatibility.

## Scheduling, monitoring and rollback

Prefer a scheduled CLI task running `php cron/send_due_reminders.php` once daily. On a host requiring HTTP scheduling, use HTTPS POST and `Authorization: Bearer <CONDO_CRON_SECRET>`; never place secrets in scheduler URLs or access-log query strings. Confirm the actual cron handler's current parameters when configuring a scheduler.

Monitor PHP errors, rejected webhook signatures, provider reconciliation failures, failed delivery, slow database queries, storage capacity and backup completion. The preflight is a release check, not a substitute for monitoring. Audit history and private documents require a property-approved retention/backup policy.

Schedule `php scripts/process_notifications.php --limit=25 --seconds=45` once per minute. Announcement, account, bill, violation, payment and due notices are queued for this worker; a queued message is not a delivery confirmation. Failed messages back off and stop after five attempts. Monitor `notification_outbox.status='failed'` and worker results, and investigate provider configuration before retrying jobs. Obsolete reminders, changed recipient addresses and superseded account decisions are cancelled. Account-decision jobs carry the committed account version, so a later unit assignment or account change invalidates earlier approval/rejection notices. A worker crash after provider acceptance can cause a duplicate delivery; settlement itself remains idempotent. Authentication recovery/code delivery is immediate and rate limited.

Superadmin can inspect pending/sent/failed notices and retry failed delivery from **Notification Delivery** in the staff menu. Retries require CSRF protection and are audited; recipient and relevance checks still apply when the worker picks up the notice.

The delivery page separates Email and SMS and shows recent worker activity from the private `private_uploads/notification-worker.json` heartbeat. A successful worker run means it checked the queue; inspect each notice's delivery status as well. Email `sent` means SMTP acceptance, not proof that the receiving mailbox placed it in the inbox. SMS requires its own provider credentials; SMTP configuration does not enable SMS.

For local Windows/XAMPP, configure the existing **CondoSystem Notifications** scheduled task with program `C:\xampp\php\php.exe`, arguments `"C:\xampp\htdocs\CondoSystem3\scripts\process_notifications.php" --limit=25 --seconds=45`, and Start in `C:\xampp\htdocs\CondoSystem3`. Repeat every minute, allow starts on battery, do not stop when switching to battery, enable Start when available, keep IgnoreNew for overlapping instances, and stop the task after 3 minutes so a stuck process cannot block later runs for days. The PHP batch has a 45-second claim budget; the scheduler limit also bounds blocking provider/OS calls. An interactive task runs while that Windows user is logged in. On hosting, use the hosting provider's CLI cron scheduler instead.

Due-reminder generation is separate from delivery: use the existing staff action or schedule `php cron/send_due_reminders.php` daily when automatic due reminders are required. The minute worker delivers already queued notices and does not invent reminders, bills or approval decisions.

`php scripts/backup.php --directory=<private directory outside the web root>` exports the transactional database and copies private uploads without putting a password in command arguments. It omits routines/events; export and review those separately if your installation uses them. Back up the code release and environment/signing keys separately. A backup is complete only when the command succeeds and restoration has been checked on an isolated database.

For a current installation snapshot, `php scripts/validate_upgrade.php --backup=<snapshot/database.sql>` checks its manifest checksum, restores into a uniquely named disposable database, runs migration/preflight, verifies existing account/workflow/financial states are preserved, and removes the generated database and account. Operator credentials need CREATE/DROP DATABASE, CREATE/DROP USER and GRANT privileges. Dump SQL and child migration/preflight commands use temporary credentials restricted to that one database; cross-database/global statements cannot use the operator's privileges. DEFINER or other nonportable exports can be rejected and require a reviewed portable dump. The tool never falls back to privileged restoration. Protect the snapshot itself: its manifest detects corruption, not malicious replacement of both files.

If smoke checks fail before reopening writes, restore the prior database/uploads/configuration/code together. If new production writes or real payments have occurred, freeze traffic and reconcile those records/provider events before any restoration; restoring a backup alone can lose settlements or new requests. Keep a record of the release version and backup timestamp.

## Automated verification

```text
php scripts/test_resident_workflows.php
php scripts/test_deployment.php
php scripts/test_authentication.php
php scripts/test_authorization.php
php scripts/test_payment_workflows.php
php scripts/test_notification_outbox.php
php scripts/test_backup_restore.php
php scripts/test_paymongo_bank_flow.php
php scripts/test_tenant_policy.php
php scripts/test_tenant_billing.php
```

The workflow and deployment suites create unique disposable databases and remove them in `finally`. They need database create/drop privileges, and the workflow suite also needs Node.js for rendered JavaScript checks. Backup restoration tests additionally need account creation/grant/removal privileges for restricted temporary accounts. Run them against a test database server, with no production secrets or delivery recipients. `test_deployment.php` validates a base-schema migration, idempotency, nontransactional-storage rejection, version recording, unique-index validation and readiness failure on incomplete production settings. `test_backup_restore.php` verifies manifest failures cannot report success and that database switching, executable comments and global operations cannot escape the restricted restore account.

See [VERIFICATION.md](VERIFICATION.md) for the local release check results and their scope.

## Private community reports

See [COMMUNITY_REPORTS.md](COMMUNITY_REPORTS.md) for case permissions, anonymous credentials, evidence retention, backups and verification. The additive release can be installed with `php scripts/migrate_community_reports.php --apply` on an otherwise current installation; the full migration includes it. Configure `CONDO_REPORT_INDEPENDENT_REVIEWER_IDS` with the approved property manager account IDs. Accused or conflicted accounts remain excluded, including the property manager. An oversight case with no eligible configured reviewer stays sealed.

The backup command now copies outside-webroot report evidence into `community_report_evidence` in each snapshot. Restore that directory to the configured private report storage together with the matching database and audit key. Run `php scripts/purge_report_evidence.php` to inspect retention candidates; schedule the same command with `--apply` daily once the property approves the configured retention.

## Visitor parking fees and extensions

See [VISITOR_PARKING_FEES.md](VISITOR_PARKING_FEES.md) for the PHP 50/hour policy, actual gate movements, extended-stay approvals, tenant billing, legacy consent and operational checks. On an otherwise current installation, run `php scripts/migrate_visitor_parking.php --apply`; the full migration also installs schema release `2026.10.14.3`. Keep the existing minute notification worker scheduled: it now checks parking thresholds and extension expiry before delivering queued notices. Monitor its private heartbeat for parking errors independently of delivery results.


## Staff job reports (2026.10.14.4)

See [JOB_REPORTS.md](JOB_REPORTS.md). After backing up, run `php scripts/migrate.php --apply`, then `php scripts/preflight.php`. This additive migration installs job-report drafts, immutable filed snapshots/review history, source actor/time indexes and database triggers. Migration requires CREATE TRIGGER privilege; normal runtime access does not. Keep triggers in backups. Existing notification scheduling is reused. PHP GD, ZIP and the installed Dompdf fonts provide PDF and paginated PNG exports. `CONDO_JOB_REPORT_MAX_ACTIVITIES` defaults to 2000; exceeding it fails explicitly instead of omitting work.
