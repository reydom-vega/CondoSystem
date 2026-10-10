# Property Gate Pass

The existing Permit Requests screen now includes **Property Gate Pass**, with Bring In and Pull-Out directions. Existing Move-in, Move-out, Renovation, Delivery and visitor workflows remain available.

## Upgrade and configuration

Run `C:\xampp\php\php.exe scripts/migrate.php --apply` from the project directory. This is the repeatable migration, including schema version `2026.10.09.4`. Local installations with `CONDO_AUTO_MIGRATE=1` also add the new schema on first use. Production requires the explicit CLI migration before serving the release.

`database.sql` contains the complete schema, including property gate passes, for a fresh installation. Import it into an empty database, then run the PHP migration to initialize settings and record readiness. Existing installations use the PHP migration; do not reimport the fresh-install schema over existing records.

- `CONDO_TENANT_PERMITS=1` enables tenants and authorized occupants. Approved ownership links and unit assignments are still required.
- `CONDO_PERMIT_UPLOAD_MAX_MB=5` is the optional per-document limit (1–20 MB). PHP `upload_max_filesize`, `post_max_size` and `max_file_uploads` must accommodate the selected limits. At most five documents can be uploaded at once and ten retained per request.
- `CONDO_PASS_SIGNING_KEY` should be a persistent random 64-character hex secret in production. The existing persisted access-signing-key fallback works locally. Rotating this key revokes previously generated QR codes.
- Dates and movement windows follow the application's existing `Asia/Manila` timezone. Each movement is within one day. An end time is inclusive through its displayed minute's `:00` second.

Uploads are stored under protected `private_uploads/permit_documents`. Only JPG, PNG and PDF actual MIME types are accepted; images are additionally checked. Filenames are randomized. `permit_document.php` authorizes the owner, management or security before serving a document with a restrictive sandbox policy. Direct HTTP access to the storage directory remains denied by `.htaccess`.

## Workflow

1. An approved resident or tenant opens Permit Requests, selects Property Gate Pass, lists 1–50 items and chooses a date and start/end time. The requester and unit come from the authenticated database account; posted identity fields are ignored. One transaction saves the request, items, attachments and history. `PGP-` plus the unique request ID is its permanent request number.
2. Admin/superadmin opens the existing Permit Management screen and views all details. They approve (optionally changing the schedule), reject with a reason, or request changes with a reason. Security cannot approve property movements.
3. A returned request can be edited and resubmitted by its requester. Existing supporting documents remain attached. Resubmission replaces its item list atomically and returns it to Pending. Requester or management can cancel pending, returned or approved passes.
4. Approval issues a domain-separated unpredictable verification token; the module stores its SHA-256 hash. The approved pass displays the authorized items, schedule, approver and QR. Use Print / Save as PDF for the browser's PDF export. Pending, rejected and cancelled requests have no issued gate-pass action or QR.
5. The existing security scanner validates the current database status, approved resident relationship, unit and time window. The scanner records the staff verification outcome and does **not** complete it. Opening or refreshing the pass displays current validity without creating another scan event. Staff can open the verified details to inspect all items.
6. After a physical check, security or management explicitly confirms completion with an optional remark. Completion locks the request during the transition, records the staff ID and time, invalidates the issued QR hash, and prevents replay. A completed pass remains viewable for history but cannot authorize movement.

Expired is calculated from the stored authorized end date/time when rendering and verifying, so it does not depend on a cron job. The persisted approval remains auditable; it never makes an expired pass valid. Request, decision, verification and completion events are retained in `permit_history`.

## Storage and changed files

The existing `resident_service_requests` retains requester, category, dates, review fields and nonce. Added `gate_data` stores direction, purpose, requested/authorized schedule, immutable requested unit, transport and completion information; `qr_token_hash` stores the issued token hash and `updated_at` records changes. Related `permit_items`, `permit_documents` and `permit_history` hold repeating rows.

New files: `includes/property_gate_pass.php` (schema and workflow), `includes/permit_requests_content.php` (form/history), `includes/permit_details.php` (shared preview/review), `includes/property_gate_pass_page.php` (digital pass), `permit_document.php` (protected documents), `js/permit-requests.js`, `scripts/test_property_gate_pass.php`, `scripts/gate_fixture_router.php` (isolated HTTP test router), and this guide.

Updated integration files: `config.php`, `includes/resident_services.php`, `includes/resident_services_page.php`, `includes/visitor_parking.php`, `includes/access_scanner.php`, `includes/resident_accounts.php`, `includes/deployment_schema.php`, `resident_service_pass.php`, `services.css`, `.env.example`, `.gitignore` (allow the consolidated database schema), and `TENANT_CONFIGURATION.md` (correct the enabled permit menu description).

## Verification

Run the isolated suites with PHP: `scripts/test_property_gate_pass.php`, `scripts/test_resident_workflows.php`, `scripts/test_tenant_policy.php`, and `scripts/test_deployment.php`. They use disposable databases. The property suite covers multi-item storage, identity spoofing, review decisions, correction/resubmission, cancellation, schedule validation, token forgery, scanner integration, completion, replay, owner isolation, transaction rollback, and genuine HTTP multipart uploads (valid PDF/PNG, MIME-spoof rejection), CSRF, protected downloads and route authorization. Add `--preview` to keep its loopback-only disposable UI open until you press Enter; no working condominium data is exposed by that fixture.

Camera permissions, physical item inspection and the browser's print/PDF dialog require interactive verification on the target device.
