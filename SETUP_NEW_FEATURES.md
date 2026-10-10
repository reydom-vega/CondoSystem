# Feature setup

Use [DEPLOYMENT.md](DEPLOYMENT.md) for installation, upgrades, private configuration, scheduling and release checks. See [ARCHITECTURE_REVIEW.md](ARCHITECTURE_REVIEW.md) for role ownership and workflow design. This guide replaces the earlier automatic-schema and source-code credential setup instructions.

## Configuration and storage

Copy `.env.example` to a private environment file and supply new database and provider credentials. `CONDO_ENV_FILE` can point outside the public directory. Do not store secrets in PHP files or commit environment files. Revoke credentials previously exposed in source or archives.

Back up an existing database and private uploads before running `php scripts/migrate.php --apply`. Production requires the migration marker, `CONDO_AUTO_MIGRATE=0`, a canonical HTTPS URL and restricted runtime database credentials. The `database.sql` baseline is for a fresh installation only. Run the deployment preflight before opening production traffic.

## Roles and service flows

- Superadmin approves resident accounts, manages staff, configures parking inventory/policy, and monitors notification delivery. Admin handles operational reviews; treasurer handles billing and payment confirmation. Security reviews visitors and visitor parking, validates gate passes and reads the vehicle registry. Maintenance works on management-approved requests.
- Residents register expected visitors and optionally request visitor parking in one submission. A valid parking pass requires an approved registration and an assigned visitor slot. Security confirms arrival and departure.
- Residents register their own vehicles with OR/CR. Management reviews them in `superadmin/registeredvehicles.php`. Unit owners select approved own or linked occupants' vehicles for sticker orders, receive the bill, and pay before physical issuance by management.
- Permit decisions belong to management. Amenities enforce capacity, operating hours and date conflicts. Maintenance completion awaits resident confirmation or reopening.
- Monthly bills are generated once per approved unit owner and period. Tenants can view unit bills; only the unit owner can pay. Earlier balances remain separate. Fine and sticker bills do not suppress monthly billing; disputed fines are reviewed by management. A checkout-bound bill cannot be manually marked paid or changed to bypass provider reconciliation.

## Providers and delivery

Configure PayMongo keys and a matching webhook secret privately. Register your actual HTTPS endpoint at `/webhooks/paymongo_webhook.php` and test `checkout_session.payment.paid` in the matching provider mode. Settlement checks the signature, actual checkout, amount, currency and provider payment reference. A success-page visit alone never confirms payment.

Configure SMTP and the selected SMS provider through the names in `.env.example`. Authentication recovery and verification messages are immediate and rate limited. Operational notices and payment receipts use a durable queue: schedule `php scripts/process_notifications.php --limit=25 --seconds=45` every minute. Superadmin can inspect delivery status and retry failed jobs in **Notification Delivery**. Queued notices have not yet been delivered.

Schedule `php cron/send_due_reminders.php` daily to queue due notices. If using an HTTP scheduler, use HTTPS POST with `Authorization: Bearer <CONDO_CRON_SECRET>`; keep secrets out of URLs. Consult the deployment guide for host compatibility, backup restoration, monitoring and controlled provider/browser smoke checks.
## Tenant accounts

Use [TENANT_CONFIGURATION.md](TENANT_CONFIGURATION.md) for the recommended signup, owner approval, service permissions and read-only billing setup. Tenant payment and new sticker-order authority stay restricted to the approved unit owner.
