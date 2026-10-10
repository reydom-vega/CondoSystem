# System UI redesign

## Scope and architecture

Every existing browser-facing screen uses the shared dark design system. Role guards, query handlers, form field names, CSRF tokens, payment callbacks, QR camera code and database schema stay intact. Tenant is an account type under the resident role; it has no separate PHP page directory. Admin and specialist roles reuse authorized superadmin modules. Treasurer and Maintenance are included.

### Workspace layout refinement

`includes/portal_ui.php::portalWorkspaceModule()` explicitly opts the role dashboards, billing, violations, parking, maintenance, messages, announcements, visitor registration/logs, vehicle registries, account management, reports and notification delivery into `assets/css/workspace.css` and `assets/js/workspace.js`. Shared staff routes receive the same presentation for every authorized role. Scanner pages, amenity booking/review, permit requests, payment return, access passes and authentication pages do not load these assets.

The refinement raises body/form/table typography, groups visible filter labels above their controls, gives dynamically added bill charges unique associated labels, and displays ordinary records as stacked cards below 768px. Statement-of-account, receipt history and penalty-rate tables retain their compact table layout. Real table headers and explicit roles remain available to assistive technology. Long management lists stay inside keyboard-accessible scrolling regions. Parking Configuration and Notification Delivery now use the standard sidebar and account header. All form names, action values, request destinations and server permissions remain unchanged.

Run `php scripts/test_ui_redesign.php --preview`, then pass the printed loopback base URL to `node scripts/test_workspace_browser.cjs <base-url>` for tablet/320px coverage and read-only charge/navigation checks. `--samples` captures desktop/390px screenshots for visual review. The existing `scripts/test_ui_browser.cjs` covers all role/page entries and workflow interactions. These tests use disposable data; they do not send real payment or email requests.

## Implementation plan

- [x] Scan PHP entry points, shared templates, styles, scripts, permission gates and existing workflows.
- [x] Add shared palette, typography, cards, forms, tables, badges, dialogs and responsive shell.
- [x] Connect every browser-facing template; preserve email and PDF styling.
- [x] Add accessible desktop collapse, mobile drawer, breadcrumbs and account footer.
- [x] Add local GSAP and reusable motion with reduced-motion and failure fallbacks.
- [x] Review role dashboards and every management/resident/account workflow.
- [x] Run syntax, workflow, authorization, payment, QR and responsive browser checks.

## Existing palette

Navy #0a0f1d, sidebar #111827, charcoal #1a1e2b, primary orange #d97706, orange hover #b45309, amber link #f59e0b, white #ffffff, muted #9ca3af; extracted from styles.css, resident.css and security.css. Green/red/blue/amber remain semantic status colors.

## Page and template checklist

Connected records static integration. Browser checks and regression checks are recorded separately; static integration alone is not an end-to-end test.

| Page / shared template | Role / purpose | Design system | Verification |
| --- | --- | --- | --- |
| forgot_password.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| homepage.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| login.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| logout.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| parking_pass.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| resend_verification.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| reset_password.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| resident_service_pass.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| signup.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| signuppending.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| verify.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| verify_pending.php | Public / account / pass | Connected | Reviewed; desktop / tablet / mobile |
| admin/admin_dashboard.php | Admin | Connected | Reviewed; desktop / tablet / mobile |
| includes/notify.php | Email HTML; retain email-safe markup. | Print/email preserved | Source reviewed |
| includes/property_gate_pass_page.php | Shared template | Connected | Reviewed; desktop / tablet / mobile |
| includes/qr_scanner_page.php | Shared template | Connected | Reviewed; desktop / tablet / mobile |
| includes/qr_scan_report.php | PDF template; retain print styling. | Print/email preserved | Source reviewed |
| includes/resident_services_page.php | Shared template | Connected | Reviewed; desktop / tablet / mobile |
| includes/vehicle_registry_page.php | Shared template | Connected | Reviewed; desktop / tablet / mobile |
| maintenance/maintenance_dashboard.php | Maintenance | Connected | Reviewed; desktop / tablet / mobile |
| resident/announcements.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/book_amenity.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/dashboard.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/edit_profile.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/maintenance.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/messages.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/parking.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/payments.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/payment_receipt.php | PDF receipt; retain print styling. | Print/email preserved | Source reviewed |
| resident/payment_return.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| resident/residentviolation.php | Resident / Tenant | Connected | Reviewed; desktop / tablet / mobile |
| security/security_dashboard.php | Security | Connected | Reviewed; desktop / tablet / mobile |
| security/visitor_log.php | Security | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/admin_dashboard.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/admin_messages.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/analytics.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/announcements.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/auditlog.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/bookingrequest.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/generate_bills.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/maintenancerequests.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/notification_delivery.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/parking.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/parkinginventory.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/parking_configuration.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/pending_accounts.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/residents.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/staff.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/unitpayments.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/units.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/violations.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| superadmin/visitorlog.php | Superadmin | Connected | Reviewed; desktop / tablet / mobile |
| treasurer/treasurer_dashboard.php | Treasurer | Connected | Reviewed; desktop / tablet / mobile |

## Pages by role

The exact paths are listed above and in inventory/ui-pages.json. Shared management routes keep the existing capability checks.

| Role | Updated screens |
| --- | --- |
| Superadmin | Dashboard; units; residents; pending accounts; staff; billing and payments; bill generation; violations; amenity approvals; maintenance; messaging; announcements; vehicles; visitor registrations; permits; parking and stickers; parking inventory/configuration; scanner/history/export; visitor log; analytics/reports; audit log; notification delivery. |
| Admin | Dashboard; units; residents; violations; amenity approvals; maintenance; messaging; announcements; vehicles; visitors; permits; parking; scanner/history; visitor log. Billing links remain hidden when unauthorized. |
| Security | Dashboard; violations; registered vehicles; service request verification; parking verification; scanner/history/export; visitor entry/exit log. |
| Resident / Tenant | Dashboard; bills/payments; violations; amenity catalogue/details/galleries/booking/history; parking/stickers; vehicles; maintenance; messaging; announcements; visitors/group visits; permits/property gate passes; profile/password. Payment return is also connected. Tenant permissions and owner visibility remain enforced. |
| Treasurer | Dashboard; shared billing/payments; bill generation. |
| Maintenance | Dashboard; shared maintenance request management. |
| Public / account / passes | Homepage; login; two-step signup; forgot/reset password; email verification/resend/pending; account approval/rejection state; sign out confirmation; permission/session recovery screens; visitor, property gate and parking passes. |

## Shared route wrappers

| Entry point | Template / behavior |
| --- | --- |
| index.php | Redirects to homepage.php. |
| maintenance/maintenancerequests.php | Redirects to the authorized shared maintenance management page. |
| resident/permits.php | require __DIR__ . '/../includes/resident_services_page.php'; |
| resident/vehicles.php | require __DIR__ . '/../includes/vehicle_registry_page.php'; |
| resident/visitors.php | require __DIR__ . '/../includes/resident_services_page.php'; |
| security/scanner.php | require __DIR__ . '/../includes/qr_scanner_page.php'; |
| superadmin/registeredvehicles.php | require __DIR__ . '/../includes/vehicle_registry_page.php'; |
| superadmin/scanner.php | require __DIR__ . '/../includes/qr_scanner_page.php'; |
| superadmin/service_requests.php | require __DIR__ . '/../includes/resident_services_page.php'; |

## Exclusions

API/webhook endpoints, document and evidence downloads, scheduled jobs, diagnostics and CLI tests have no application UI to redesign. They are inventoried in inventory/ui-pages.json but do not receive browser assets. PDF receipts, scan reports and email HTML retain their own portable print/email styling. Existing pass pages receive screen styling with print overrides.

## Validation results

All mutation tests used automatically cleaned disposable databases. No production data or external payment provider was used.

| Regression suite | Passing checks |
| --- | ---: |
| Resident workflows | 220 |
| Authorization and lifecycle | 170 |
| Tenant policy and lifecycle | 185 |
| Payment workflows | 69 |
| Tenant billing | 163 |
| Authentication | 47 |
| QR scan history | 81 |
| Property gate passes | 70 |
| Pool reservations, galleries and billing integration | 99 |
| Deployment | 27 |
| **Regression total** | **1,131** |
| UI routes, assets and CSRF markup across 89 role/page combinations | 446 |

PHP syntax passed for all 150 application/test PHP files outside vendor; JavaScript syntax passed for 14 scripts. Shared-head audit found exactly one integration in each of the 50 browser templates and the three intentional document/email exclusions. Git whitespace checks passed with the repository's CRLF line endings allowed.

Browser checks below passed with no page overflow, local 404/500 responses, duplicate GSAP imports or uncaught JavaScript exceptions. Final verification completed on 2026-10-10 (Asia/Taipei).

### Browser verification

- All 89 role/page combinations were checked at 1440px desktop, 768px tablet and 390px mobile: 267 page/viewport checks. These check page overflow, visible content, one GSAP import, local resource failures, JavaScript exceptions and filter sizing.
- 35 additional interactions cover persistent sidebar collapse; mobile drawer focus/Escape; real pool availability, fee and submission; cancelled/confirmed cancellation; scanner history; 320px page/dialog sizing; reduced motion; blocked GSAP; signup step labels; invalid/valid login; profile labels; pending approval; mobile analytics/filters; older confirmation/editor focus; Function Hall submission/cancellation; local image lightbox; permit conditional-field submission and single confirmation; Admin billing visibility.
- Selected screenshots were visually reviewed for role dashboards, login/signup, billing, units, permits, messaging, booking, profile and analytics. Screenshots are created in the system temporary directory; each browser report records its directory.
- Machine-readable results: inventory/ui-browser-results.json, inventory/ui-tablet-results.json and inventory/ui-interaction-results.json.
- Dashboard status presentation was checked against seeded approved and pending accounts for both Admin and Superadmin; missing unit assignments display Unassigned.

Run `C:\xampp\php\php.exe scripts/test_ui_redesign.php --preview` in a terminal. Use the printed loopback base URL with `node scripts/test_ui_browser.cjs <base-url>` and `node scripts/test_ui_browser.cjs <base-url> --tablet-only`. Optional `--interactions-only` runs the 35 interactions. Press Enter in the preview terminal to shut down the server and drop its database. The fixture accepts only its generated disposable database and loopback requests; the normal application does not accept fixture actors.

## Files and integration

- Shared presentation helpers: includes/portal_ui.php (loaded by config.php).
- Shared CSS: assets/css/design-system.css, components.css and responsive.css, loaded after existing page styles.
- Shared behavior: assets/js/ui-components.js; menu state, breadcrumbs, role labels, accessible tables, required markers and custom-overlay focus.
- Shared motion: assets/js/animations.js; page/panel/card entrance, actual metric counters, drawer links, native dialogs, custom overlay entrance, galleries, availability/summary feedback, chat and alert updates.
- Local dependency: assets/vendor/gsap/gsap.min.js, version 3.15.0, with its original license notice and source attribution.
- Navigation footer: permission-filtered resident and staff navigation renderers. Existing links and capability lists are retained.
- Every browser template in the table above receives one renderPortalUiHead call and the portal-ui body class. There is no output-buffer injection into API or document responses.
- Retired duplicate sidebar listeners and redundant confirmation imports; legacy auth page transitions now use immediate native navigation.
- Related template edits add persistent account-form labels and move the analytics report action into a responsive toolbar. Permission/CSRF failures use includes/authorization.php, includes/resident_policy.php and includes/workflows.php to render the shared recovery screen while retaining HTTP status and API behavior.
- Existing script integration edits: js/confirmation-ui.js, js/page-transition.js, js/services-menu.js, js/amenities.js, js/qr-scanner.js and js/permit-requests.js. The permit handler uses the shared confirmation once and retains its native fallback.
- Older page-specific styles remain for business-specific controls and markup; shared components override the common visual rules without renaming handlers or form fields.
- UI verification harness: scripts/test_ui_redesign.php, scripts/ui_fixture_router.php and scripts/test_ui_browser.cjs. Operational scripts remain HTTP-blocked by the existing .htaccess.

## Deliberate behavior and limits

- No database or SQL change was required. Existing local amenity files are untouched.
- Only Swimming Pool and Function Hall can be booked. Pool pricing, overlap prevention, approval billing and the chosen cancellation policy remain in the existing backend.
- Navigation continues to use the actual role and tenant permissions. Inaccessible billing links were removed from the Admin dashboard. Recent resident badges now display the actual account status, including pending/inactive, and missing unit assignments are labeled.
- Real search, filters, pagination and exports are retained; no decorative search controls, fake notifications or invented statistics were added.
- Short animations never conceal content while waiting for GSAP. Reduced-motion users receive static content, and core interfaces work if GSAP is blocked. Camera reader/video elements and QR canvases are excluded from motion.
- Browser fixtures use representative data and empty states. Live hardware camera access, real outbound email/SMS, live PayMongo checkout and a real provider webhook are not exercised by this UI test run. Backend payment verification and gate/QR rules are covered by the isolated regression suites.
