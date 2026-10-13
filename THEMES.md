# Portal themes

Dark is the original and default theme. The existing stylesheets still supply its colors, fonts, sizes, spacing and component layouts. Theme-aware legacy colors use `var(--theme-…, original-color)` so dark rendering keeps the original value.

`includes/portal_ui.php` loads `assets/js/theme.js` synchronously in the shared head. It applies the validated `condo-theme` localStorage preference before body content renders, defaults to dark if storage is missing or unavailable, and mounts one accessible Sun/Moon button immediately before the notification menu. Public pages receive the same control. Preference updates sync across browser tabs and restore from the back/forward cache.

`assets/css/theme.css` centralizes light palette overrides and theme control styling. Theme changes apply immediately: the shared bootstrap briefly suppresses CSS transitions, resolves the new palette synchronously, then restores normal component interactions before returning. No timeout or color fade is used; existing CSS and GSAP animations remain intact. Add future component colors through the existing design-system variables or semantic adapters with the exact dark fallback. The shared `buildingicon.php` supplies theme-specific logo URLs: `celandine.svg` for dark and `celandineorange.svg` for light. The head bootstrap updates parser-created images before paint and updates existing images when the theme changes, without changing their dimensions. Keep photographs and QR pixels unchanged; photo overlays and QR paper have intentional fixed contrast. PDF receipts and report exports retain their document palettes.

Local stylesheet links include a file modification version. Use `portalUiAssetUrl()` for shared local assets, or keep an existing `filemtime()` version; unversioned legacy CSS can otherwise leave white text and dark fills in Light Mode after an upgrade. A normal page refresh now fetches the updated asset URLs.

Switching uses no requests, session writes, database changes or GSAP replays. Optional integrations can listen for `portal:theme` on `document` and read `event.detail.theme`, or use `window.CelandineTheme.get()` / `.set('light' | 'dark')`.

Authenticated profile menus also have a `Light/Dark Mode` switch immediately before Logout, after My QR ID. It uses the same controller and storage preference as the navbar button, stays open while switching, and synchronizes its accessible switch state and icon with navbar, cross-tab, and history-restored theme changes. Existing Edit Profile links and other menu items are retained.

Verification uses disposable databases only:

```text
C:\xampp\php\php.exe scripts/test_ui_redesign.php --preview
node scripts/test_theme_browser.cjs <printed fixture base URL>
```

The browser suite checks every fixture route in both themes, storage persistence, early restoration, menus, small headers, keyboard access, reservation drafts, billing tabs and unchanged QR output. `--baseline` captures original dark computed styles before a design change; a later normal run compares against that temporary baseline. `--interactions-only` runs the focused interactions without repeating the route sweep. Screenshots and contrast review data are written to a temporary directory. The contrast review checks normal text at 4.5:1, large text and visible SVG icons at 3:1, including inherited opacity. `--appearance-only` runs the route and logo appearance checks without repeating the interaction scenarios.

To reproduce an older browser cache, run the appearance suite with `--reproduce-stale-css`; it substitutes the original committed legacy CSS and records the resulting contrast issues. `--stale-css --appearance-only` supplies those old styles only for unversioned asset requests and fails if their colors leak into the current UI.
