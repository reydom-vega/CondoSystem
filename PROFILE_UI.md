# Global profile settings and private photos

Residents and tenants keep `resident/edit_profile.php`. All staff roles use `edit_profile.php`, which loads the same controller and view. The shared dropdown keeps Edit Profile, My QR ID, Light/Dark Mode and Logout in that order. Username, role, assigned unit and staff ID remain administrator controlled. Staff see applicable existing account fields rather than unit fields.

The existing three cards remain: Profile Overview, Personal Information and Account Security. Ordinary updates preserve existing validation, email reauthentication and session handling. Password changes use their separate existing action, current-password verification, secure hash and session revocation. The legacy combined POST action stays supported for existing clients; the rendered forms submit independently.

## Pictures and editor

The complete avatar is an accessible Change profile picture button. Its camera appears centered on hover or keyboard focus. The native file picker accepts JPG/JPEG, PNG and WebP up to 5 MB. A dependency-free canvas editor provides a circular preview, drag, arrow-key repositioning, zoom slider, touch pinch, Reset, Cancel and Apply Photo. The preview exports a 512 ? 512 JPEG only after confirmation. GSAP entrances respect reduced motion. Upload feedback uses the existing button loader, without a fullscreen overlay.

The original file is never stored permanently. The API authenticates the live account, applies existing self-service authorization and CSRF protection, and rejects another target ID. It validates actual MIME, file contents, suffix, byte size and dimensions before decoding. Server GD re-encodes to a fresh 512 ? 512 JPEG, removing original metadata and appended data. Direct API uploads are limited to 4,194,304 pixels and 4096 pixels per dimension; the browser crops larger source photos locally (up to 20 megapixels). No SVG or executable uploads are accepted.

Random identifiers are stored in nullable `users.profile_photo`, with JPEGs in `private_uploads/profile_photos`. Root, parent and directory Apache rules deny direct access. The private image endpoint authorizes the current user or existing eligible identity reviewers (`digitalIdCanReview`); other accounts and anonymous visitors cannot retrieve images. Versioned media URLs change on replacement and send no-store/nosniff headers. No filesystem path or original name is returned. Replacement/removal locks the user's row, preserves all unrelated fields and removes only unused safe filenames after commit. Failed database writes roll back and remove the new file. Removal requires browser confirmation.

`includes/profile.php` is the reusable PHP avatar helper. `assets/js/profile-avatar.js` supplies one renderer and `portal:profile-updated` event. It synchronizes navbar, dropdown, sidebar, overview and the existing Digital ID, using one UTF-8 initial fallback if a file is missing. Only a change timestamp is put in localStorage: other open tabs fetch their own fresh profile. Authorized Digital ID verification and scanner views retrieve the current photo on new verification; anonymous QR responses still omit identity details. The QR payload, 60-second expiration, revocation and visitor/parking passes are unchanged.

## Deployment

Run `php scripts/migrate_profile_photos.php --apply` for an otherwise current installation, or use the normal full release migration. This adds one nullable field without changing existing account data. The standalone SQL file documents the one-time additive DDL for manual administration. Production installations must also run the idempotent PHP migration to validate readiness and record the release version; do not reapply the raw ALTER after the field exists. Keep the upload directory writable by PHP, preserve its `.htaccess`, and back up photos with the database. Enable GD with JPEG/PNG/WebP support for the web PHP worker. On XAMPP, enable `extension=gd` and restart Apache. On Hostinger, enable gd in PHP Configuration/Extensions and check the web worker uses the updated configuration. Readiness checks include GD and the storage denial rules.

## Verification

- `php scripts/test_profile_photos.php`: disposable MySQL and real multipart HTTP tests for every role, actual format validation, oversized/unsafe content, pixel limits, ownership, CSRF, private media, replacement/removal, metadata stripping, failed-write rollback, unchanged credentials/roles and fresh authorized QR photos.
- `php scripts/test_authentication.php`: existing login/session/email behavior, independent password updates, field isolation and rollback.
- `php scripts/test_digital_id.php`: expiry, revocation, authorization and anonymous privacy.
- `php scripts/test_ui_redesign.php --preview`: disposable role/page fixture. Pass its loopback base URL to browser scripts below, then press Enter to remove the fixture and its uploads.
- `node scripts/test_profile_photos_browser.cjs <fixture-base>`: all seven account types, both themes and 1366/768/390/320px layouts; crop/saved-image comparison, avatar synchronization, removal, Digital ID photos, keyboard file picker, mouse drag, touch pinch/drag, invalid-file rejection, failure/retry, and open-tab updates (including Remove Photo controls). Authorized verification-page and scanner photo rendering are checked in an isolated browser context. `--editor-only` runs the focused crop interactions.
- `node scripts/test_profile_browser.cjs <fixture-base>`: existing resident/tenant personal and password submissions, validation, readonly fields and loading cleanup. `--controls-only` covers mobile menus/theme/QR controls.
- `node scripts/test_digital_id_browser.cjs <fixture-base> --quick`: existing secure QR workflow across roles. `--profile-only` checks the shared dropdown and logout workflows.

Browser checks use headless Chrome against disposable data; screenshots are reviewed in both themes. Real device image pickers, camera hardware, SMTP delivery and Hostinger configuration still require deployment smoke checks.
