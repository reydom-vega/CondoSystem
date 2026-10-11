# Parking navigation

Residents and tenants use `resident/parking.php`, with **Parking & Sticker** and
**Registered Vehicles**, and **Requests** tabs. Requests shows parking history and sticker order status with a count badge; resident tabs match the amenity navigation style. Tenant feature settings still control whether each
tab and its forms are available. Vehicle lists remain scoped to the logged-in
resident; existing owner sponsorship rules for sticker ordering are unchanged.

Admin and Superadmin use `superadmin/parking.php`, with **Overview**, **Parking
Slots**, **Registered Vehicles**, **Sticker Requests**, **Visitor Parking**, and
**Parking Records**. Admin can view inventory and use existing assignment,
vehicle review and sticker issuance actions. Inventory imports, status changes
and pricing configuration still require Superadmin. Configuration is available
inside Parking Slots.

The old `vehicles.php`, `registeredvehicles.php`, `parkinginventory.php` and
`parking_configuration.php` entry points remain valid form targets and open the
appropriate workspace tab. Existing controllers render shared content partials
through `includes/parking_workspace.php`; their upload, permission, CSRF and
billing checks still run before any output. Security retains its vehicle route
and authorized parking operations.

Tab links work without JavaScript. With JavaScript, switching preserves form
input, supports keyboard navigation and browser history, and uses the existing
GSAP library when reduced motion is disabled. Deep links reveal their containing
tab. Only authorized panels are rendered. No schema migrations are needed.

Parking Records reads existing parking requests and sticker orders, including
their payment and issuance status. There are no copied records or new tables.
Sticker orders still create bills through the existing billing functions, and
issuance still requires verified payment. PayMongo checkout and webhook handlers
are unchanged.

Validation commands (disposable databases; no provider calls):

```powershell
C:/xampp/php/php.exe scripts/test_authorization.php
C:/xampp/php/php.exe scripts/test_resident_workflows.php
C:/xampp/php/php.exe scripts/test_tenant_policy.php
C:/xampp/php/php.exe scripts/test_payment_workflows.php
C:/xampp/php/php.exe scripts/test_ui_redesign.php --preview
# In another terminal, use the printed loopback base URL:
node scripts/test_parking_workspace_browser.cjs http://127.0.0.1:PORT/CondoSystem3/
# Press Enter in the preview terminal to remove the fixture and uploaded files.
```
