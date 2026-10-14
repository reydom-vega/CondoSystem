# Homepage Radial Bloom intro

The supplied `celandine-homepage-integrated.zip` was extracted and compared before integration. Its homepage.css matches the existing stylesheet, and its homepage.php differs only by adding the intro include. The existing homepage content, PHP redirects, links, gallery, mobile menu, contact details and homepage.js are preserved.

## Files

- **Modified:** `homepage.php` adds the scoped intro stylesheet/controller in the head and the markup include at the start of the body.
- **Modified:** `homepage.css` keeps the accessibility skip link hidden until keyboard focus and uses readable theme colors. The main content is programmatically focusable so activating the link bypasses navigation.
- **Created:** `includes/celandine-intro.php` reuses the ZIP's 32 individual SVG petal paths and Radial Bloom markup. The ZIP's duplicate CDN GSAP import is omitted.
- **Created:** `assets/css/celandine-intro.css` contains only namespaced intro styles, responsive sizing and reduced-motion/print handling.
- **Created:** `assets/js/celandine-intro.js` uses the project's existing local GSAP, sessionStorage, Skip/Escape controls, background focus handling and cleanup.
- **Created:** `scripts/test_homepage_intro_browser.cjs` contains read-only guest browser checks.
- **Created:** this integration/testing guide.

`js/homepage.js` is unchanged; the only homepage.css changes concern the existing accessibility skip link. The duplicate standalone SVG/reference JPEG in the ZIP are unnecessary because its animated SVG is already embedded in the include. No database, authentication, billing, shared loading controller or other module is modified.

## Behavior

The white flower blooms petal-by-petal on a navy background, with subtle rotation/scaling, gold wordmark/halo accents and a smooth fade revealing the original page. Skip Intro stays in the upper-right corner; Escape also dismisses the animation. The intro lasts approximately three seconds.

The SVG uses a square viewBox centered on the artwork's bounds, keeping the unchanged flower paths centered inside the circular halo at every viewport size. After completion or Skip Intro, focus returns to the previously focused page control or the page body instead of automatically revealing “Skip to main content.” That link remains available on the first keyboard Tab; Enter focuses the main content.

`sessionStorage.celandineIntroSeenV1` is marked at startup, so refreshing during the intro, skipping, navigating away or returning cannot replay it in that tab session. Reduced motion bypasses the intro. Blocked session storage also bypasses it. Markup stays hidden if the controller cannot load; missing GSAP/CSS, initialization failures and an eight-second safety timeout release the page. Page-hide cleanup prevents a frozen overlay after back/forward navigation. Focus/inert/scroll changes are cleaned up when the intro ends.

The controller is loaded synchronously in the head to decide visibility before body rendering. It starts the animation after the existing deferred local GSAP is ready. The intro's asset URLs use `portalUiAssetUrl()` and its include uses `__DIR__`, following the existing application base URL on XAMPP and Hostinger. Deploy the new files together with homepage.php and retain the existing `assets/vendor/gsap/gsap.min.js`.

## Manual testing

1. Sign out, open a fresh browser tab, and visit `homepage.php`. Confirm the individual petals bloom and the homepage appears normally afterward.
2. Refresh and navigate to Login or Sign Up, then return. The intro should not replay in the same tab.
3. To replay deliberately, run this in the browser console:

   ```js
   sessionStorage.removeItem('celandineIntroSeenV1');
   location.reload();
   ```

4. Click **Skip Intro** during the animation. Confirm the overlay disappears immediately and homepage links, scrolling, gallery and mobile menu work. Repeat using Escape. The accessibility skip link should remain hidden after dismissal or automatic completion; press Tab to reveal it, then Enter to focus the main content.
5. Test desktop, tablet, phone portrait and phone landscape. The logo and Skip control should fit the screen. The intro uses the supplied navy/white/gold composition while retaining the saved theme on the underlying homepage.
6. Enable `prefers-reduced-motion: reduce` in browser rendering tools, clear the session key and reload. The homepage should appear without the animation.
7. Block the intro JavaScript, its stylesheet or the local GSAP request in browser tools, clear the key and reload. The page should remain accessible rather than getting trapped behind an overlay.

New independent tabs normally have fresh session storage. Browser “Duplicate tab” and tabs opened with an opener can inherit session storage, so use a fresh tab with the address entered directly when testing a new session, or clear only the key above.

## Automated checks

```text
php -l homepage.php
php -l includes/celandine-intro.php
node --check assets/js/celandine-intro.js
node scripts/test_homepage_intro_browser.cjs http://localhost/CondoSystem3/
```

The browser script uses the installed Windows Chrome, a fresh temporary profile and localhost GET requests. It does not log in, submit forms, send messages or create database fixtures. It checks the existing sections, local GSAP import, all 32 petals, automatic completion, session persistence, navigation, Skip/Escape, responsive layout, reduced motion and unavailable storage/assets. Screenshots are saved to its printed OS temporary directory. External Google font/map requests are blocked only inside the test browser to keep the checks independent of network availability.

Implementation verification: PHP and JavaScript syntax passed; all 32 archived SVG path geometries remain unchanged; 43 Chrome browser checks passed, including precise flower/halo centering at four viewport sizes, hidden skip-link behavior after completion/dismissal, and keyboard activation. Desktop and mobile viewport screenshots were visually inspected. Local tests used a temporary read-only loopback PHP server; no application database migration or authentication/payment operation was performed. An actual Hostinger deployment was not performed.
