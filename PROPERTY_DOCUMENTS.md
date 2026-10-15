# Official property information on generated documents

The document identity is centralized in `includes/branding.php`, through
`getPropertyInformation()`:

- **THE CELANDINE**
- **1115-A A. Bonifacio Ave., Brgy. Balintawak, Quezon City**
- Email: `celandinehomes@gmail.com`
- Website: `thecelandinesystem.online`

The existing configuration/schema contains no property identity settings table.
The existing branding module is reused; no table, migration, dependency, or
unconfigured contact number was introduced.

## Existing outputs covered

| Output | Integration |
| --- | --- |
| Paid billing receipts, including parking and violation/booking charges | Shared PDF header with orange SVG and original serif font; generation time and page numbers |
| Paid visitor parking receipts | Same protected receipt route and existing parking breakdown, with a specific parking receipt title |
| Payment receipt email | Existing light email logo and complete address in the header |
| Billing statements, violation notices, booking/maintenance/visitor notices and other shared notification emails | One small property footer through the existing email layout |
| Verification resend and password reset emails | One small footer, preserving existing message and action links |
| Daily/monthly staff reports and management downloads of filed versions | PDF, numbered PNG pages, and themed preview, for all existing staff roles |
| Audit reports | PDF, PNG/ZIP and printable preview, retaining complete authorized filters and redaction |
| Analytics reports, all existing scopes | PDF and PNG/ZIP; existing charts, aggregates and availability notes preserved |
| QR scan history reports | PDF, including continuation-page identification |
| Visitor access pass pages | Themed header, address and generation footer; Download Image saves the complete visitor details, schedule, status and verification QR as a PNG |
| Legacy permit pass pages | Existing themed header, address, generation footer, downloads and white-paper print styles |
| Property gate passes | Themed/printable page, PDF and client-generated JPG |
| Visitor parking passes | Themed page and original QR download, now with a property header outside the QR quiet zone |

Separate maintenance completion PDFs, amenity confirmation PDFs, vehicle/parking
inventory PDFs, unit certificates and account-statement PDFs are not implemented
as standalone generators in this repository. Their existing email notices and
reporting views are covered where applicable; no new modules were invented.

## Layout and integrity

`brandDocumentHtml()` supplies the complete property header to existing PDF
generators. `brandPdfFooter()` uses the reserved bottom margin, with generation
time and page numbers. Continuation pages repeat the complete property address
in the footer instead of on every data row. First pages already have the full
address in their header.

GD reports reuse their existing orange PNG lockup and bundled fonts through
`brandImageHeader()`/`brandImageFooter()`. Each image page has its own header;
pagination capacity accounts for the added address. No logo artwork or filter
recoloring was introduced. Screen previews retain the existing theme controller,
white logo in Dark Mode and orange logo in Light Mode.

The address describes the property. It does not replace a resident, visitor,
legal billing issuer, registered business address or TIN. Existing paid-only
receipt lookup and authorization remain unchanged. No tax identifiers or legal
receipt classification were added.

Filed job report JSON, hashes, versions, notes and transactions are unchanged.
Branding is added only when presenting/exporting an authorized snapshot.
Previously stored immutable files, uploaded identity/evidence/OR-CR documents,
financial records, authentication, payment settlement, rates, webhooks and
CSV/JSON export schemas were not rewritten.

## Files changed for this update

- Shared helpers/styles: `includes/branding.php`, `assets/css/branding.css`.
- PDF/image generators: `includes/job_report_export.php`,
  `includes/audit_report_export.php`, `includes/analytics_export.php`,
  `includes/qr_scan_report.php`, `includes/permit_pass_export.php`,
  `resident/payment_receipt.php`.
- Pass presentation/downloads: `resident_service_pass.php`, `parking_pass.php`,
  `includes/property_gate_pass_page.php`, `assets/css/resident-service-pass.css`,
  `assets/js/resident-service-pass.js`, `assets/js/visitor-pass-download.js`,
  `assets/js/permit-pass-download.js`.
- Staff preview: `includes/job_report_workspace.php`, `assets/js/job-reports.js`.
- Email presentation: `includes/notify.php`, `includes/payment_receipts.php`,
  `forgot_password.php`, `resend_verification.php`.
- Tests: new `scripts/test_property_branding.php` and
  `scripts/test_property_branding_browser.cjs`; document fixture selection in
  `scripts/test_ui_redesign.php`; missing audit table initialization in the
  isolated `scripts/test_property_gate_pass.php` fixture.
- This coverage and verification document.

Earlier Digital ID and Treasurer dashboard edits in the working tree are
separate work and have been preserved.

## Verification

Run presentation tests without accessing a database or providers:

```powershell
C:/xampp/php/php.exe scripts/test_property_branding.php
```

Generate private QA documents in disposable databases:

```powershell
C:/xampp/php/php.exe scripts/test_job_reports.php
C:/xampp/php/php.exe scripts/test_audit_log.php
C:/xampp/php/php.exe scripts/test_system_analytics.php
C:/xampp/php/php.exe scripts/test_property_gate_pass.php
C:/xampp/php/php.exe scripts/test_notification_outbox.php
C:/xampp/php/php.exe scripts/test_tenant_billing.php
```

For browser checks, keep this fixture running, then pass its printed loopback URL
to each browser script:

```powershell
C:/xampp/php/php.exe scripts/test_ui_redesign.php --preview --property-documents-only
node scripts/test_property_branding_browser.cjs http://127.0.0.1:PORT/CondoSystem3/
node scripts/test_permit_workspace_browser.cjs http://127.0.0.1:PORT/CondoSystem3/ --downloads-only
node scripts/test_job_reports_browser.cjs http://127.0.0.1:PORT/CondoSystem3/
```

Press Enter in the fixture terminal afterward to clean up its private database
and server. The browser tests use fictional accounts and private temporary files.

Validation performed locally on PHP **8.2.12**:

- 21 presentation/MIME preparation checks; 136 job report checks; 133 audit checks; 149 analytics
  checks; 89 gate pass checks; 58 notification checks with mocked delivery;
  203 tenant billing checks. No external provider contacted.
- 273 route/asset/CSRF checks in the document fixture; 82 staff report browser
  checks; 6 approved owner/tenant permit JPG/PDF download checks.
- PDF text extraction checked all 84 pages of long job, audit, analytics, scan
  and permit fixtures for the complete address and pagination. First,
  continuation and last pages were rendered for visual review.
- All 49 generated report PNG pages were checked for identical complete property
  headers; header/footer regions were visually reviewed. Paid billing/parking
  receipts and real owner/tenant permit downloads were separately rendered.
- 76 property document browser checks cover mobile/desktop, both themes, all five
  staff previews, single-page white-paper printing of the standard pass fixtures
  and preservation of every pixel in the downloaded parking QR image.

PHP 8.3 and production hosting were not available for runtime testing. Email
templates and queue behavior were tested without sending real mail; mailbox
delivery and physical printer output remain deployment checks.
