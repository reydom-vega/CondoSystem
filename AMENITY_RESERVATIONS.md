# Amenities and Swimming Pool reservations

The existing resident booking page now also serves as the Amenity Information page for owners, tenants and authorized residents. Existing account permissions still apply. Only Swimming Pool and Function Hall offer reservation forms; all seven other amenities show information and local photos only.

## Local photographs

`includes/amenity_catalog.php` maps all nine amenities to their original files in `assets/amenities/`. No photographs were downloaded, copied, renamed or modified. The extensionless Swimming Pool, Function Hall, Sky Lounge and Landscape Garden files are WebP; the directory's `.htaccess` supplies the correct MIME type under Apache with `nosniff` enabled. On other web servers, configure those four exact filenames as `image/webp`.

Resident cards use responsive cropped images and a larger, uncropped lightbox. Public homepage amenity cards link to the local photograph in a larger browser preview. Missing images show an accessible text placeholder. Images below the first row load lazily. If additional matching local photographs exist (for example, `amenity-swimming-pool-2.jpg`), the existing gallery automatically supplies next/previous controls and keyboard navigation in the lightbox. The current directory has one photograph per amenity. Operating hours or precise locations absent from the existing project are identified as requiring PMO confirmation rather than invented.

## Pool schedule and pricing

- Exclusive sessions, 1–3 whole hours, on the hour, within the existing 6 AM–9 PM operating schedule.
- Maximum 20 guests. Guest count never changes the fixed PHP 300/hour price.
- Date/time display and validation use Asia/Manila.
- Pending requests do not block pool availability. Approved, confirmed and completed reservations occupy their original interval.
- Two intervals conflict exactly when `requested_start < existing_end AND requested_end > existing_start`. Adjacent sessions are allowed.
- The client refreshes availability on date/duration changes, explicit refresh, window focus and every 30 seconds while the reservation dialog or staff schedule is visible. A submission is always checked again on the server.
- A conflict returns HTTP 409 with `code: schedule_conflict`, a title and explanatory message. Selection is cleared and availability is refreshed.

Creating requests and approving/rejecting/cancelling/completing bookings use the same MySQL named mutex, scoped by database, amenity and date. The mutex is held until the database transaction has committed or rolled back. Approvals also lock the stored resident/owner accounts and booking; conflict queries use current locking reads. Independent administrators therefore cannot approve overlapping pool requests, even if both were pending at the time they opened the page. Multiple application instances must use the same MySQL server for this mutex strategy.

## Billing and cancellation

Approval creates a dedicated `payments` statement and one `bill_items` line of type Amenity Reservation, then links it through `bookings.payment_id` in the same transaction. The line contains the booking reference, date, interval, unit, hours and rate. An approved booking stays approved/unpaid until an existing verified PayMongo event or authorized cash receipt settles the bill, which changes the linked booking to confirmed. Client redirects and Admin booking actions cannot mark pool payments paid.

The requesting tenant's stored unit-owner relationship determines the unit billing account. Exactly one owner-account bill is issued; tenants retain their existing read-only access to shared unit bills. Pool invoices have the `amenity_reservation` billing scope and cannot be extended with arbitrary bill items or recalculated away from the booking snapshot.

Repeated approval is idempotent. A failed conflict, charge insert or audit rolls the whole approval back. Money snapshots use DECIMAL, with the fixed hourly calculation performed on the backend.

Selected policy: future unpaid reservations without an online checkout can be cancelled, releasing their hours and voiding the dedicated unpaid statement (`payments.status = rejected`, `gateway_status = booking_cancelled`). Paid reservations and reservations bound to an online checkout require PMO review and remain active until that review. A staff member with both booking review and billing management permissions (currently Superadmin) can record an explicit reviewed cancellation and reason. Paid amounts and online checkout references remain intact for financial review; **the system does not issue an automatic gateway refund**. A late trusted payment for a PMO-cancelled reservation settles its financial record without reopening the cancelled reservation. Residency revocation releases the reservation and flags retained paid/checkout records for PMO review.

Function Hall retains its exclusive full-day reservation behavior, including a pending hold. This change does not introduce automatic Function Hall billing.

## Database and upgrade

All installation SQL remains in root `database.sql`; no separate migration SQL file is created. Version `2026.10.09.5` extends the existing `bookings` table with unit, end time, duration, DECIMAL rate/fee snapshots, linked payment, approval audit fields and decision notes. It adds the schedule lookup index and a unique linked-payment index. No additional booking or billing table is created.

For an existing installation run `php scripts/migrate.php --apply`, then `php scripts/preflight.php`. The migration is repeatable. Legacy pool sessions keep their existing confirmed status and gain a one-hour interval without retrospective billing. Legacy pending pool requests with no pricing snapshot require cancellation and resubmission before approval. Existing overlapping legacy shared sessions are retained for PMO resolution and block any new conflicting approval.

## Files and validation

Modified: `includes/amenities.php`, `includes/billing.php`, `includes/payment_confirmation.php`, `includes/resident_accounts.php`, `includes/deployment_schema.php`, `resident/book_amenity.php`, `resident/dashboard.php`, `superadmin/bookingrequest.php`, `homepage.php`, `homepage.css`, `js/homepage.js`, `database.sql`, `DEPLOYMENT.md`, `scripts/gate_fixture_router.php`, `scripts/test_resident_workflows.php`.

Created: `includes/amenity_catalog.php`, `includes/amenity_lightbox.php`, `api/amenity_bookings.php`, `amenities.css`, `js/amenities.js`, `assets/amenities/.htaccess`, `scripts/test_pool_reservations.php`, this document.

The pool integration suite uses disposable MySQL databases, concurrent PHP approval processes and real loopback HTTP. Existing payment, deployment, resident workflow, tenant policy, tenant billing and authorization regression suites remain provider-free. Browser QA exercises local images, the lightbox, availability, selection, fee updates, conflict refresh and responsive layouts. No real PayMongo checkout or payment is initiated during testing.
