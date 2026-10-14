# Visitor parking fees and extensions

Release `2026.10.14.3` extends the existing visitor registration, QR pass, vehicle log, parking inventory, payments, receipt and notification modules. It adds movement and billing columns to `parking_requests` and one `parking_extensions` decision-history table. No separate payment processor or duplicate session ledger is introduced.

## Policy and movement records

- PHP 50.00 per started hour, from Security-confirmed actual vehicle entry to actual vehicle exit. Registration, visitor approval, parking approval and QR generation do not start the timer.
- Exactly 24 hours remains standard parking. Longer stays need Admin/Super Admin extension approval. Fees continue without a daily cap, midnight reset, discount, forced checkout or automatic penalty.
- Pending/rejected extensions do not authorize an extended stay. Approved extensions have an absolute authorized end. A later overstay remains active and can receive another extension request.
- Integer microseconds/seconds, hours and centavos are used for calculations; monetary snapshots use DECIMAL storage. Datetimes are stored in UTC with microsecond precision and displayed in the configured Philippine timezone. Legacy date-only records retain their existing interpretation.
- Checkout saves the server timestamp, final hours, rate snapshot, compliance state, linked invoice and audit event together with vehicle/visitor exit and slot release. An error rolls back the entire movement. Reservation locks and unique indexes prevent duplicate active plates, occupied slots and invoices for a session.

Expected and actual durations can differ. An estimate is not a confirmed charge. Approval of a planned extension never changes the actual entry timestamp or stops the meter. Approvals retain their request, decision reason, reviewer and timestamps even when later expired or cancelled.

## Resident and tenant workflow

Use the existing Visitor Registration form or add parking to an eligible existing visitor from Parking & Vehicles. Select vehicle type, plate, optional model/color, requested slot, expected arrival/departure and acknowledge the fee policy. A plan beyond 24 hours requires a free-text extension reason. Existing mandatory ID verification and visit/parking approvals remain required for entry.

Parking & Vehicles > Requests shows authorized sessions, live estimates, extension history and Request Parking Extension. Visitors without vehicles create neither a vehicle session nor a parking bill.

The existing tenant billing policy makes tenants responsible for their own parking. Tenant-sponsored visitor fees therefore stay with the tenant's `personal_parking` account. Owner-sponsored visitor fees use `visitor_parking`; linked occupant responsibility is resolved through the existing owner relationship. Ambiguous or unapproved accounts cannot start a vehicle session. The payer and sponsoring unit are saved at entry and are not silently reassigned later.

Vehicle-session invoices are visible/payable only to the responsible resident account and authorized billing staff. Existing shared unit statements and legacy sticker statement behavior are preserved. Removing occupancy does not release an occupied visitor slot, erase an active fee clock or change its original payer. Security can still select that active session for checkout.

## Management and Security

Admin/Super Admin can now use **Review & Approve** in Visitor Parking, or **Review ID & Approve Parking** in Visitor Registration. One shared review window displays the protected ID, visitor/purpose, sponsor/unit, vehicle, expected duration/fee and slot. After reviewing the image, **Verify ID & Approve** verifies the ID, approves the visit and assigns/approves parking in one transaction. A pending planned extension is included by a clearly labeled, preselected option; clearing that option leaves the extension pending.

The image must load before the button becomes available. Submission checks the current ID and request version, account eligibility and slot/extension conflicts. Any failure rolls back every decision, audit and queued notice. Repeated successful submissions do not repeat the approvals or notifications. Existing individual review/rejection controls remain available under the separate-decision sections; Security keeps its existing review workflow. This change requires no database migration and never starts the parking timer.

Admin/Super Admin: Parking Management > Extended Parking Requests contains the searchable request table, duration/fee estimates, requested slot, reason, decision history and details. Approve only after confirming the assigned slot and conflict-free requested period; rejection requires a reason. A shortened approval must still cover a future end beyond 24 hours and stay within the requested period.

Security: use the existing QR Scanner or Visitor Parking workspace. Verify the person, vehicle and assigned slot, then select Confirm Actual Vehicle Entry. Scanning alone does not record vehicle entry. Review Vehicle Checkout shows the session estimate; Confirm Checkout & Add to Billing recalculates and commits the final charge. Admin/Super Admin can review sessions but cannot confirm physical vehicle movement under this policy. Security cannot approve extensions.

Expired visit dates do not automatically end an already checked-in vehicle session. Exit remains available from the active-session selection even if its original QR is no longer usable. Public QR pages receive no ID document or full ID number from these additions.

## Existing billing, payment and receipts

Each checkout adds one `Visitor Parking Fee` bill item to a pending invoice in the existing payments module. Its billing period is the local entry month; the due date follows the existing parking billing rule of 15 days. The linked session retains its movement, plate, unit, rate, hours and final fee. Final session invoices cannot have unrelated charges appended manually.

The normal online/bank/cash workflows, PayMongo callbacks and reconciliation remain in use. Browser redirects never mark an invoice paid. Only verified settlement or authorized cash confirmation issues a paid receipt. The existing system email receipt and protected PDF include parking movements, duration, hours, rate, final fee and payment details. Receipt notifications use the transactional outbox and existing recipient rules.

Examples: 1 hour = PHP 50; 1h30m = PHP 100; 24h = PHP 1,200; 24h1m = PHP 1,250; 48h = PHP 2,400; 72h = PHP 3,600; 51h = PHP 2,550.

## Upgrade and legacy reservations

Back up the database, uploads and matching code/configuration first. On an otherwise current installation run:

```text
php scripts/migrate_visitor_parking.php --apply
php scripts/preflight.php
```

The full `php scripts/migrate.php --apply` also includes this release. Both migrations are repeatable. `database.sql` contains the fresh-install schema. The SQL file in `migrations/` is a reference for the extension table; use the PHP migration for the complete, idempotent existing-table upgrade.

Historical parking is not charged retrospectively. Existing future reservations use policy version 0 until their sponsoring resident explicitly acknowledges the new fee policy through Requests. Their expected times must fit the already-approved dates; the assigned slot is checked again for conflicts. Version-0 vehicle reservations cannot begin a new chargeable vehicle session without that acknowledgment. Existing visit, ID, parking and payment records are retained.

## Monitoring and operations

The existing `scripts/process_notifications.php --limit=25 --seconds=45` worker runs parking monitoring before normal queue delivery. It warns as parking approaches 24 hours, flags unapproved/expired extended stays and expires obsolete requests. Each batch checks up to 500 active sessions in least-recently-monitored order, plus up to 500 expired pre-arrival extensions. Larger populations require successive runs. Authorized workspace loads provide additional checks.

Outbox keys deduplicate notices. A parking-monitor fault is recorded separately and does not stop unrelated email/SMS queue delivery. Check the private `private_uploads/notification-worker.json` heartbeat for `parking_monitor` results or an error; this file must remain inaccessible over HTTP. Provider retry and delivery settings remain unchanged.

On the inspected local installation, Windows task **CondoSystem Notifications** already runs once per minute. Its heartbeat confirmed the new parking monitor executed on 14 October 2026. Other hosts must schedule the worker; loading pages alone is not unattended background monitoring. Neither browser tests nor queued notifications prove receipt by a real email/SMS recipient.

## Verification

```text
php scripts/test_visitor_parking_fees.php
php scripts/test_visitor_identity.php
php scripts/test_resident_workflows.php
php scripts/test_tenant_billing.php
php scripts/test_payment_workflows.php
php scripts/test_notification_outbox.php
php scripts/test_authorization.php
php scripts/test_deployment.php
php scripts/test_ui_redesign.php --visitor-parking-only --preview
node scripts/test_visitor_parking_browser.cjs <printed-loopback-base-url>
```

These tests use disposable databases and provider fixtures. They do not issue a real PayMongo checkout, deliver a real notification or alter resident business records. The Chrome suite covers five roles, dark/light themes, desktop/tablet/mobile layouts, actual entry/checkout controls, extensions, billing privacy, form estimates and the existing PDF receipt route. Camera hardware and live provider delivery require controlled operational checks.
