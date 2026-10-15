# Admin and Super Admin dashboards

The dashboards now prioritize work that needs attention instead of filling the
screen with lifetime totals. Both roles retain the existing navy/orange palette,
Light Mode, sidebar, navbar, profile controls, notifications, and Digital ID.

## Recommended daily workflow

1. Start with **Needs your review**. Each count opens its existing management
   module. Verify and approve there using the current workflow.
2. Follow up on **urgent maintenance**, then older active work.
3. Open **unread resident conversations**, grouped by resident rather than by
   individual messages.
4. Check the **upcoming amenity bookings** for today and the next six days.
5. Super Admin can additionally review account applications, staff reports,
   financial follow-up, and failed notification delivery.

Community Reports remains a shortcut to the existing private case workflow.
Confidential complaint bodies, evidence, work notes, and message bodies are not
copied into a new dashboard access model.

## Shared operational overview

Cards use two independent vertical stacks on desktop, so a short review queue
does not leave a large empty row before maintenance. On smaller screens the
stacks become one column. Layout checks cover both populated and short queues.

- Four populated metrics: pending review tasks, active maintenance, unread
  conversations, and inventory units linked to approved residents.
- Review queues cover maintenance requiring assignment, pending bookings,
  visitor registrations, permits, parking requests including legacy resident
  requests, extensions, vehicles, paid unissued stickers, and violation
  reviews/disputes. Clear queues are collapsed under a separate disclosure.
- Property information shows unit inventory, approved resident accounts,
  visitors currently inside, and active announcements.
- Useful shortcuts and follow-up links open existing routes and supported
  search/filter parameters.
- Recent resident accounts retain their current approval/verification state.

Counts are **tasks**, not unique people. A visitor registration and its related
parking request can each require attention. Review counts do not approve anything
or replace the destination module's checks.

## Super Admin additions

- Verified, active resident applications awaiting approval.
- Submitted staff reports awaiting independent review, excluding the current
  Super Admin's own submissions.
- Monthly collections, current outstanding balance, and past-due balances/counts.
- A follow-up link when notification delivery has failed. The dashboard does not
  retry or send messages.

Admin receives no financial balance panel, account approval queue, staff review
queue, notification delivery details, or analytics link. Paid-sticker task counts
use the existing `stickers.issue` capability and expose only the count/readiness
already available in Parking Management.

## Corrected definitions

- **Collections:** paid records within the current Manila calendar month by
  `paid_at`, not `created_at`. Paid records lacking a payment date are excluded
  from that month's total and explicitly noted. Monetary aggregates use integer
  cents after database DECIMAL aggregation.
- **Outstanding:** bills with status `pending` or `overdue`; rejected and
  rolled-forward bills are excluded.
- **Past due:** outstanding bills whose actual due date is before today,
  including overdue dates still stored with `pending` status. The dashboard never
  changes their stored status.
- **Unit registration:** distinct normalized inventory unit numbers linked to
  active, verified, approved resident accounts. Owners and tenants sharing a unit
  count once. This is not a physical occupancy inspection. Percentages show one
  decimal place instead of rounding small nonzero occupancy to 0%.
- **Active maintenance:** pending, approved, in progress, or reopened; completed,
  closed, rejected, and cancelled work is excluded.
- **Unread conversations:** distinct residents with unread resident-originated
  messages, with the individual unread message count shown separately.
- **Visitor presence:** the sum of group sizes in checked-in visits without a
  checkout time, rather than the number of registrations.
- **Open violations:** approved/legacy unpaid or disputed records; the old
  nonexistent `unresolved` status query is removed.

## Refresh and failure behavior

The page renders a consistent, read-only server snapshot immediately. Manual
refresh and a one-minute visible-page timer use the existing protected dashboard
API with the correct application base URL.

Only one refresh can run at a time. Background refresh pauses for hidden pages,
focused dashboard links, or expanded review disclosures, and rechecks interaction
after a request finishes. Offline, failed, or timed-out requests retain the last
successful snapshot with readable feedback. Timers and pending requests are
cleaned up on navigation and restarted after a back/forward cache restore.

The old dashboard-specific `live-updates.js` include is removed from these two
pages; it used an incorrect root `/api/` path and did not refresh their cards.
Other pages and their existing scripts are unchanged.

The API remains authenticated, GET-only, and `private, no-store`. It preserves
the original numeric response keys for existing consumers and adds the shared
snapshot/rendered content. Actor/session checks use the existing authentication
and capability system. No role capability has been expanded.

## Changed files

- `admin/admin_dashboard.php`, `superadmin/admin_dashboard.php`: canonical
  entry points for the shared page, with the correct role title.
- `includes/management_dashboard.php`: read-only queries and role-specific data.
- `includes/management_dashboard_content.php`: shared escaped dashboard view.
- `includes/management_dashboard_page.php`: existing shared navigation/profile
  structure and dashboard composition.
- `api/admin_dashboard.php`: shared snapshot, permissions, method guard and
  backward-compatible numeric keys.
- `assets/css/management-dashboard.css`: scoped layout and responsive styling.
- `assets/js/management-dashboard.js`: refresh, focus preservation and cleanup.
- `includes/ui_icons.php`: reusable refresh icon in the existing icon helper.
- `scripts/test_ui_redesign.php`: optional `--management-only` disposable fixture.
- `scripts/test_management_dashboard.php`: data, permission and integrity tests.
- `scripts/test_management_dashboard_browser.cjs`: browser and refresh tests.

Earlier working-tree changes, homepage/login styling, unit inventory CSV,
branding assets, authentication, financial transactions and approval modules
were preserved. No database migration or dependency was added.

## Verification

```powershell
C:/xampp/php/php.exe scripts/test_management_dashboard.php
C:/xampp/php/php.exe scripts/test_ui_redesign.php --preview --management-only
node scripts/test_management_dashboard_browser.cjs http://127.0.0.1:PORT/CondoSystem3/
```

Use the preview fixture's printed port and keep it running during browser checks.
Press Enter in its terminal afterward to remove its private database and server.

Verified locally:

- 52 backend checks covering every queue, finance boundaries and cents, unique
  units/conversations, empty data, actor/session checks, escaping, privacy and
  unchanged database records.
- 43 route, shared asset and CSRF checks for the two dashboard entry points.
- 212 browser checks cover both roles and themes at 1366, 1024, 768, 390 and 320 px,
  populated counts, role separation, shared Digital ID/profile/mobile navigation,
  protected GET-only API, manual/background refresh, focus preservation, hidden
  pages, offline feedback, failed refresh recovery and valid destination files.
- Desktop and mobile screenshots reviewed in Dark and Light Mode. Existing
  colors and artwork preserved; no horizontal page overflow in tested widths.
- PHP and JavaScript syntax checks passed.

Tests use fictional records in disposable databases and do not contact payment,
email, or SMS providers. Runtime testing used local PHP 8.2.12 and Chrome;
production PHP 8.3/hosting behavior remains a deployment check.
