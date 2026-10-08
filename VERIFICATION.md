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
