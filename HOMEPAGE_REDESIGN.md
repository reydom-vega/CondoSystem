# Luxury homepage integration

The supplied `celandine-luxury-homepage-redesign.zip` was extracted and compared with the existing homepage before integration. Its layout was adapted rather than copied over the working application.

## Changed files

- `homepage.php`: photographic hero, compact overview cards, six amenity and six interior photographs, four resident service cards, location/contact layout and footer.
- `homepage.css`: styles scoped to `portal-homepage`, dark/light palettes, responsive navigation, galleries and native image-preview dialog. No shared stylesheet was changed.
- `js/homepage.js`: gallery arrows and keyboard/swipe support, accessible image previews, mobile navigation, GSAP entrances/section reveals and subtle parallax.
- `scripts/test_homepage_redesign_browser.cjs`: read-only guest browser checks using installed Node and Chrome.
- This integration/test guide.

## Preserved components and content

The original authentication bootstrap and role redirects, `config.php`, `buildingicon.php`, `includes/ui_icons.php`, shared theme/storage/loader controllers, all three existing intro files, actual photographs, Google Maps embed/directions URL, PMO contacts, every FAQ answer and the complete Privacy Policy and Terms of Service are retained. No database, authentication, payment, role or dashboard files were changed.

The existing Radial Bloom intro already provides the requested 2.9-second animation, individually animated white petals, centered circular halo, Skip Intro/Escape, once-per-tab behavior and reduced-motion handling. Homepage entrances wait for it to finish. The existing local GSAP import is reused; native IntersectionObserver handles scroll reveals without adding ScrollTrigger or another framework.

The homepage uses the shared `condo-theme` preference and `data-theme` attribute. The original white/orange brand assets switch through the shared controller. The ZIP's separate `celHomeTheme` preference and replacement logo were not introduced.

No image assets are missing. The extensionless swimming-pool WebP uses its existing `assets/amenities/.htaccess` MIME rule on Apache. Fonts use the existing Google Fonts approach with Cormorant Garamond added for headings; Georgia/system sans fallbacks remain readable if the font service is unavailable.

## Preview

Start Apache in XAMPP and visit `http://localhost/CondoSystem3/homepage.php` while logged out. Existing authenticated-account redirects are intentionally preserved.

1. Open a fresh tab to see the intro, or remove `celandineIntroSeenV1` from sessionStorage and refresh. Check automatic completion, Skip Intro and Escape. Reload in the same tab to confirm it does not repeat.
2. Try the theme button and refresh. Check the white logo in Dark Mode and orange logo in Light Mode.
3. Try navigation and Login/Sign Up/Resend Verification links. On a narrow screen, open the menu, choose a section, or press Escape.
4. Use arrows, horizontal swipe, or focus a gallery and press Left/Right/Home/End. Open a photo, use Left/Right to browse, and close with X, Escape or the backdrop. Focus returns to the original photo.
5. Expand FAQ/legal items and check the PMO details and Google Maps directions.
6. Enable reduced motion in the browser/OS. The intro and motion effects are bypassed; controls remain functional.

## Automated verification

Run against a running local application (guest GET requests only; no login submissions or database fixtures):

```powershell
C:/xampp/php/php.exe -l homepage.php
node --check js/homepage.js
node scripts/test_homepage_intro_browser.cjs http://localhost/CondoSystem3/
node scripts/test_homepage_redesign_browser.cjs http://localhost/CondoSystem3/
```

The browser suites write screenshots into private temporary directories and close their own Chrome processes. They cover responsive layouts in both themes, intro cleanup, shared GSAP reuse, navigation, image previews, keyboard focus, gallery controls, image-error recovery, persistence, missing GSAP, disabled JavaScript and live reduced-motion changes.

Verified locally: 99 homepage browser checks and 43 intro checks passed. PHP and JavaScript syntax checks passed; all 35 local asset/route URLs returned HTTP 200. The original authentication bootstrap, six FAQ/legal items, map embed and three intro files were compared against the backup and preserved. Screenshots were reviewed at desktop and mobile sizes. Apache was stopped during this run, so tests used a temporary local PHP server with a process-only application URL override; `.env` was unchanged.

The original files and extracted ZIP are backed up outside the webroot at `C:/xampp/condo_backups/homepage_luxury/20261015_172734/`.

Live hosting deployment and real account sign-in remain manual checks; backend code was intentionally untouched. Local checks use PHP 8.2.12 and Chrome. Production PHP/Apache configuration was not changed.
