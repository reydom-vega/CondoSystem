# Vehicle lookup and reviewed violation reports

The existing `superadmin/violations.php` page serves security, admin and superadmin. Search by plate or parking slot, inspect a matching registration, review its history, and select **Issue Violation** to fill the existing form. Vehicle reports are saved as **Pending Review** without a liable account or billing charge. Admin/superadmin opens **Review report**, checks evidence, selects the responsible account, records verification notes and approves or rejects it.

Vehicle details are integrated into the original issuance form, with one submit button after all fields. Selecting a vehicle fills its plate, related resident/host and parking location. The incident date/time defaults to the current local time and remains editable. Use the existing Description / Evidence Notes and evidence upload fields to record the incident. A fine requires a configured violation type; a warning can use a custom type. **Clear vehicle** restores the original account-based form. The related resident/host is identification only and does not establish responsibility.

In the ordinary **Issue a Violation** form, Resident is a text input: type the existing resident/tenant's full name or use a name/unit suggestion. Matching is case-insensitive and ignores extra whitespace. Duplicate full names require the qualified suggestion with the correct unit/account. Both client and server resolve the name to an eligible existing account, clear stale IDs when the name changes, and reject unknown or mismatched identities before storing evidence or adding a charge. The account and billing relationships are unchanged; free text never creates a new resident. Vehicle lookup still fills the related resident/host as read-only reference information, and **Clear vehicle** restores manual name entry. No database migration is needed for this input change.

## Modified files

- `superadmin/violations.php`: lookup integration, vehicle-report submission, review actions and pending/rejected display.
- `includes/violations.php`: schema integration, preserved account-based legacy issuance, approved-only resident visibility and staff pending-report listing.
- `includes/parking.php`: indexed generated normalized plates for inventory and visitor requests. The previous occupied-status fix remains in place.
- `includes/deployment_schema.php`: required columns and schema version `2026.10.11.1`.
- `config.php`: reviewer notification for pending vehicle reports and distinct pending/rejected labels in analytics.
- `resident/residentviolation.php`: recorded plate, incident date/time and recorded unit in approved citation details; existing disputes remain available.
- `database.sql`: additive schema upgrade at the end of the fresh-install schema.
- `.env.example`: disabled-by-default visitor-host billing policy setting.
- `.gitignore`: track this specific SQL migration while retaining the database-dump ignore rule.
- `scripts/ui_fixture_router.php`: allow the new read-only API and protected evidence endpoint within disposable local tests.

## New files

- `includes/vehicle_violations.php`: lookup, immutable incident snapshots, scoped history, report submission, transactional approval/billing and rejection.
- `api/violation_vehicles.php`: permission-checked GET API for suggestions, vehicle details, vehicle history and incident details.
- `includes/violation_vehicle_ui.php`, `includes/violation_vehicle_fields.php`: shared lookup/dialog markup and selected-vehicle incident fields.
- `js/vehicle-violations.js`, `assets/css/vehicle-violations.css`: debounced, cancellable search; responsive cards/dialogs; history filters/pagination; autofill and review forms.
- `migrations/2026_10_11_vehicle_violations.sql`: additive MariaDB SQL upgrade.
- `scripts/migrate_vehicle_violations.php`: targeted CLI upgrade with migration locking, schema validation and version recording.
- `scripts/test_vehicle_violations.php`, `scripts/test_vehicle_violations_browser.cjs`: disposable integration/HTTP and browser checks.

## Migration

For the existing schema, run:

```powershell
C:/xampp/php/php.exe scripts/migrate_vehicle_violations.php --apply
```

The full `scripts/migrate.php --apply` upgrade also calls the new schema helpers. The targeted command avoids unrelated owner-link backfills. SQL is available separately in `migrations/2026_10_11_vehicle_violations.sql`; use the CLI command to validate and record the version rather than only importing SQL. These migrations add columns and indexes, permit `user_id` and financial `status` to be null for unverified reports, and default existing rows to review status `legacy`. They do not change existing citation statuses, original user IDs, amounts, bill items or payment links. Never import the fresh-install `database.sql` into an existing populated database.

The implementation is tested against this installation's MariaDB 10.4.32. Indexed generated plate columns use MariaDB's `REGEXP_REPLACE`. A deployment on Oracle MySQL needs its generated-column compatibility verified before applying this migration.

## Linking and history

New reports retain `vehicle_id`, `visitor_registration_id`, `parking_request_id`, `parking_slot_id`, `observed_plate`, `registration_source`, `incident_at` and an immutable JSON snapshot of available vehicle, account/host, unit, slot, level, sticker, visitor and gate details. The reviewer-selected responsible account and unit are captured separately at approval. Source references and current assignments are re-read and locked on submission; browser-supplied identity, fine amounts and liability are not trusted.

History prefers the exact registered vehicle ID or visitor-registration/request ID. Unlinked rows with the same normalized observed plate are shown as **Potential historical plate match** and retain their original recorded account. A parking-slot history match is explicitly identified as a slot match with unconfirmed vehicle identity. Matching a current account or slot owner does not reassign old citations.

Account-only legacy violations with no recorded plate or vehicle reference cannot reliably be assigned to a vehicle. They remain in the existing account history; the system does not guess a vehicle from the owner, unit or description. Original incident-time identity cannot be reconstructed for those legacy rows; details distinguish current legacy account information from recorded snapshots.

## Visitor lookup and responsibility

Visitor plates come from `parking_requests`, joined to the linked `resident_service_requests` visitor registration and its `visitor_logs` gate record, where available. Guest identity, host account, destination unit, visit dates, approval status and available check-in/out times are displayed separately. Manual gate logs without a linked parking plate are not invented as vehicle registrations. Current records are prioritized; historical/inactive matches remain selectable and labelled. Inventory-only cards are retained when no current linked vehicle card already contains that assignment.

Visitor fine approval is disabled by default. If PMO explicitly permits resident/tenant host charging, configure `CONDO_VISITOR_VIOLATION_HOST_BILLING=1`. A reviewer must still select the verified responsible account, confirm the policy applies to that incident and enter verification notes. No host is preselected. Without the policy, keep the incident pending investigation or reject it. There is no external-guest billing account in the existing system.

Unknown vehicles are reported with an observed plate and optional observed slot. They are never automatically assigned to the parking slot's owner. An authorized reviewer must establish responsibility before approval can create a charge.

## Approval and billing

The independent review states are `legacy`, `pending`, `approved` and `rejected`. Pending/rejected reports have no financial state; `unpaid` only appears after an approved fine is billed. Vehicle warnings also undergo review and create no charge. Unrelated existing account citation issuance is preserved.

Approval locks the pending report, verifies an approved responsible account and its billing-owner relationship, applies `getViolationFineRates()`, adds one item to an eligible fine-only unit bill (or creates one), links the item/payment and records the reviewer and audit event in one transaction. Repeated approval cannot create another charge. An audit/billing failure rolls back the charge and decision together. Resident/tenant ownership rules are preserved: the citation identifies the responsible tenant while the unit bill belongs to the verified unit billing owner. Personal tenant parking bills and submitted checkout totals are excluded from reuse.

Approved citations appear on the responsible resident/tenant's existing violation page. Bill payments continue to settle linked citations through the existing payment flow. Dispute, waiver and paid-state behavior remain available. Notifications are queued only after approval; a notice failure does not reverse or misrepresent a saved decision. PayMongo webhook logic is unchanged.

## Permissions, UI and limits

Lookup requires both `violations.manage` and `vehicles.read`. Report submission requires `violations.issue`; review requires `violations.review`. Related audit events require `audit.read`. Ownership documents and visitor pass secrets are excluded from lookup responses. POST actions retain CSRF validation, evidence stays in protected storage, and rendering uses escaped text.

Search uses indexed normalized plate/slot prefixes, starting at two characters, with a 300 ms debounce and at most 20 suggestions per source. Enter a longer prefix when the result limit is reached. Plate punctuation and case are normalized; partial searches match the beginning of the plate. Vehicle history supports text, type, status and incident-date filters, newest-first ordering and ten records per page. GSAP uses the existing reduced-motion-aware helpers. The existing staff history remains the account-based list, with the new pending review filter and details action.

## Validation

Verified on October 11, 2026:

| Suite | Passed checks |
| --- | ---: |
| Vehicle lookup, history, report review, billing and HTTP | 67 |
| Desktop/mobile browser interactions, form submissions and reduced motion | 33 |
| Existing payment workflows | 69 |
| Existing tenant billing | 163 |
| Existing authorization and account lifecycle | 169 |
| Existing resident, visitor, vehicle and parking workflows | 226 |
| Total | 727 |

PHP syntax checks and JavaScript syntax checks passed. The targeted migration was applied locally and rerun successfully to verify idempotence. Before/after hashes of all original columns confirmed preservation of 13 users, 2 vehicles, 800 parking slots, 3 parking requests, 3 resident service requests, 1 visitor log, 9 violations, 29 payments and 88 bill items. No test report or charge was inserted into the application's existing database; test data and test evidence files were cleaned up from the disposable fixtures.

Run:

```powershell
C:/xampp/php/php.exe scripts/test_vehicle_violations.php
C:/xampp/php/php.exe scripts/test_payment_workflows.php
C:/xampp/php/php.exe scripts/test_tenant_billing.php
C:/xampp/php/php.exe scripts/test_authorization.php
C:/xampp/php/php.exe scripts/test_resident_workflows.php
```

For browser checks, start `scripts/test_vehicle_violations.php --preview` interactively, then run `node scripts/test_vehicle_violations_browser.cjs <printed-loopback-base-url>`. The fixture uses a disposable database and local test accounts. No provider payments, emails or SMS are sent. The fixture also tests configured/disabled visitor policies, private evidence upload/access, historical account preservation, warning approvals, repeated approvals, injected rollback failures and server-side permission/CSRF rejection. Browser checks cover dialogs, keyboard dismissal, lookup results, historical labels, pagination/filters, autofill, mobile layouts, reduced motion, and actual resident-vehicle, visitor-vehicle and original account-form submissions through confirmation. Screenshots and a JSON report are written to the printed temporary directory.
