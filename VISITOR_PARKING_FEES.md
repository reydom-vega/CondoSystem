# Visitor parking rates, movements and billing

Release `2026.10.15.1` extends existing registration, QR passes, inventory, Security movements, payments, receipts and the transactional notification outbox. It uses the existing `parking_requests` session and invoice link, with one `parking_rate_versions` configuration table. Historical invoices and active legacy sessions retain their original policy.

## Default rate policy

| Actual stay | Charge |
| --- | --- |
| Up to and including 15 minutes | Free; no invoice |
| More than 15 minutes, through 3 hours | PHP 50 total |
| Regular time beyond the initial 3 hours | PHP 25 per additional started hour |
| Each completed qualifying 22:00–06:00 period | PHP 300 |

Grace and the base allowance apply once per continuous stay. Completed overnight periods replace regular charges for their covered time, without double charging. Regular time after an overnight period uses additional started hours without restarting the base allowance. Leaving before 06:00 does not qualify for that night's flat charge. Multiple completed nights are supported.

**Late arrivals after 22:00 require PMO policy confirmation.** The default `review_late` policy holds a late-arrival stay crossing 06:00 in `pending_rate_review`, without a payable invoice. Admin/Super Admin records the authorized interpretation and a reason before billing. Future policy options are `present_at_start` (late arrivals use regular rates) and `arrive_before_end` (late arrivals qualify when remaining until 06:00). Neither interpretation is silently assumed to be official.

Calculations use server timestamps, integer microseconds/centavos and Asia/Manila business boundaries; timestamps are stored in UTC. The server freezes the effective policy at actual entry. Later rate changes cannot change that session or finalized receipt. An estimate cannot settle or create a bill.

## Registration and consent

Residents/tenants use Visitor Registration, or add parking to an eligible existing visitor from Parking & Vehicles. Select Bringing Vehicle = Yes, enter vehicle details/slot, review the rate card and accept sponsor billing responsibility. Expected arrival/departure times are no longer required. Visit dates and pass validity remain separate from the actual fee clock. Visitors without vehicles have no parking session or bill.

Consent records its actor, timestamp and current rate version. Existing reservations and staff-prepared requests show a consent form under Parking & Vehicles > Requests. A changed current policy requires acknowledgment before entry.

Authorized staff can prepare a request through Visitor Registration > Requests > Staff-assisted visitor registration. The staff actor and registration authority are saved separately. Staff acknowledgment is **not resident consent**: the sponsor must accept the policy from their own account. Protected ID upload and management approval remain required.

Tenant-sponsored fees use the tenant's `personal_parking` account; owner-sponsored fees use `visitor_parking`. The responsible account and unit are resolved on the server and frozen at entry. Unrelated accounts cannot access the invoice. Removing occupancy does not erase an active session or transfer its payer.

## Approval, QR and Security movement

Admin/Super Admin retain **Review & Approve**: inspect the protected ID, verify identity, approve the visit and assign/approve parking in one transaction. Existing separate decisions and extension reviews remain available. Approval and QR generation never start the clock.

Security verifies the person, vehicle and slot through the scanner or Visitor Parking workspace. Scanning selects the corresponding session; Security must explicitly choose **Confirm Vehicle Entry**. The server checks account, consent, approval and inventory again. The workspace shows elapsed time, remaining grace and the server-calculated breakdown.

**Review Vehicle Checkout** previews the estimate. **Confirm Vehicle Checkout** records authoritative exit, calculation/compliance snapshot and movement history, releases the slot and cancels obsolete pending extensions together. Duplicate submissions cannot duplicate invoices. A database failure rolls back the movement rather than leaving an incomplete ledger.

- Free checkout: zero final amount, history and released slot; no invoice or payment action.
- Confirmed positive checkout: one existing payments invoice and Visitor Parking Fee item.
- Pending rate/account review: physical exit and slot release, no provisional payable bill. Management resolves the original account or authorized rate interpretation before final billing.

Pass expiry does not force checkout or stop billing. Security can find an active session and check it out after its original visit dates expire. Public QR verification never exposes protected ID documents.

## Extensions and versioned settings

Exactly 24 hours remains standard parking. The default policy requires Admin/Super Admin approval for longer stays. Pending/rejected extensions do not authorize an extended stay. Approval has an absolute end; fees continue without automatic penalties, midnight resets or forced exit. A future policy can explicitly disable the extension requirement.

Super Admin uses Parking Management > Slots > Visitor parking rate settings. Enter future-effective prices/durations, late-arrival rule, extension requirement and reason, then confirm. Each save creates an immutable version and audit event. Admin/Security can inspect rates but cannot change them. A scheduled version does not become current before its effective timestamp.

Keep the existing minute worker scheduled:

```text
php scripts/process_notifications.php --limit=25 --seconds=45
```

Monitoring warns about 24-hour thresholds, flags unauthorized extended stays and expires obsolete requests. The private heartbeat records parking monitoring separately from provider delivery. Outbox keys deduplicate notices. Worker execution and queued email/SMS do not prove recipient delivery.

## Billing and receipts

Confirmed charges reuse invoices, PayMongo, bank/cash confirmation, reconciliation and system email/PDF receipts. Only verified settlement or authorized cash confirmation marks payment paid. The billing period is the local entry month; the existing due-date rule remains 15 days. Final session invoices cannot receive unrelated manual charges.

Receipts include visitor/session reference, vehicle, actual entry/exit, duration, grace result, first-hours fee, succeeding-hours fee, overnight fee, version and final amount. Ordinary parking PDFs use compact spacing to keep breakdown and total together. Other receipt layouts retain their spacing.

## Upgrade and compatibility

Back up the database, private uploads, external visitor-ID storage and matching code/configuration. On an otherwise current installation:

```text
php scripts/migrate_visitor_parking.php --apply
php scripts/preflight.php
```

The full `php scripts/migrate.php --apply` also installs this release. PHP migrations are additive/repeatable; `database.sql` contains the fresh-install schema. Version-0 reservations need sponsor consent before new entry. Active version-1 sessions keep hourly calculation. Paid bills/receipts are not recalculated retrospectively. Do not import fresh-install SQL over an existing database.

No pricing environment variables are needed: database policy history is authoritative. Retain signing keys, provider credentials and existing worker settings.

## Verification

```text
php scripts/test_visitor_parking_rates.php
php scripts/test_visitor_parking_v2.php
php scripts/test_visitor_parking_fees.php
php scripts/test_visitor_identity.php
php scripts/test_resident_workflows.php
php scripts/test_tenant_billing.php
php scripts/test_payment_workflows.php
php scripts/test_notification_outbox.php
php scripts/test_authorization.php
php scripts/test_deployment.php
php scripts/test_job_reports.php
php scripts/test_ui_redesign.php --visitor-parking-only --preview
node scripts/test_visitor_parking_browser.cjs <printed-loopback-base-url>
node scripts/test_visitor_parking_browser.cjs <printed-loopback-base-url> --assisted-only
```

Tests use disposable databases and mocked providers. They cover rate boundaries, multi-night segments, snapshots, privacy, consent, Security movement, free/review checkout, invoice idempotency, payment verification, receipts, extensions and rollback. Chrome covers five roles, both themes and desktop/tablet/mobile widths. Press Enter to close the preview and remove its fixture. Live PayMongo settlement, real email/SMS delivery and physical camera scanning need controlled operational checks. See [VERIFICATION.md](VERIFICATION.md) for recorded results and runtime limits.
