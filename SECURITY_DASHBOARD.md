# Security role and dashboard

Security navigation now contains Dashboard, Violations, QR Scanner and Job Report. **Registered Vehicles, Visitor Registrations and Parking & Stickers are removed for Security.** Their management routes reject direct GET and POST requests from Security with HTTP 403. Admin and Super Admin retain those management features.

Visitor/identity review and parking approval belong to management. The narrow `vehicles.lookup` capability preserves the existing incident-report lookup without granting the Registered Vehicles management page. Security retains QR verification, approved visitor/vehicle entry and exit, incident reporting, Digital Condo ID and personal Job Reports. Gate operations use `parking.gate`; management lists and approvals use `parking.review`. Security cannot list the full parking management workspace or approve visits, identity documents, rates or extensions.

The existing Security dashboard now shows:

- **Visitors Inside:** people summed from active check-in groups with no checkout. The separate group count prevents confusing people with visit parties.
- **My Reports Awaiting Review:** pending violation/incident reports issued by the authenticated guard.
- **My QR Checks Today:** that guard's recorded verification events in the Philippine calendar day, using UTC start-inclusive/end-exclusive bounds.
- Recent visitor movements and the guard's own recent incident reports. Private incident narratives, raw QR content and other guards' reports are excluded from the response.
- Quick actions for the existing QR Scanner, incident reporting and Job Report pages.

`includes/security_dashboard.php` supplies a read-only consistent snapshot. `api/security_dashboard.php` requires an active verified Security account and matching session; other roles and guests cannot read it. Responses are private and not cached. The resident approval field (`users.status`) is not a staff login requirement; legacy Security accounts can retain its default `pending` value. Active/verified state, database role and session revocation remain enforced. The page reuses existing branding, icons, theme controls, Digital ID and navbar components.

`assets/js/security-dashboard.js` refreshes every 30 seconds while visible and provides a manual Refresh button. Requests cannot overlap, time out after 15 seconds, and stop on navigation. Offline/failed refreshes show an explicit stale-data message while retaining the last loaded figures. The old hardcoded `/api/admin_dashboard.php` poller is no longer loaded on this page. The profile dropdown opens and closes with outside click or Escape.

Responsive styles are scoped to `assets/css/security-dashboard.css`; other dashboards keep their existing layouts. No schema or business-data migration is required.

Validation commands:

```text
php scripts/test_security_dashboard.php
php scripts/test_authorization.php
php scripts/test_visitor_identity.php
php scripts/test_visitor_parking_v2.php
php scripts/test_visitor_parking_fees.php
php scripts/test_qr_scan_history.php
php scripts/test_vehicle_violations.php
php scripts/test_resident_workflows.php
php scripts/test_ui_redesign.php --security-only --preview
node scripts/test_security_dashboard_browser.cjs <loopback-fixture-base-url>
```

Press Enter in the preview process when finished to remove its disposable database. The browser suite verifies both themes and widths 1366, 768, 390 and 320, direct-route denials, role restrictions, refresh/error cleanup, profile controls and QR Scanner availability. Tests use fictional data and do not contact providers or require camera hardware.
