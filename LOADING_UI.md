# Shared loading UI

Every page using renderPortalUiHead() loads the same theme-aware component. The existing data-theme attribute and saved condo-theme preference choose the original white celandine.svg or orange celandineorange.svg. Both SVGs are preloaded. One image is displayed, without filters or artwork changes. Only the separate orange ring rotates; the logo has a small opacity animation. Reduced-motion mode stops all loading animations.

Full-screen feedback appears after 400 ms for native same-window page transitions and form submissions. Fast transitions, prevented tab navigation, downloads, new tabs, background polling and ordinary AJAX requests do not trigger a full-screen overlay. The component never intercepts navigation, wraps fetch, disables submitters, changes form values, retries requests, or changes authorization/payment handling.

Existing asynchronous form handlers that disable their submit button gain a delayed button spinner, removed when that handler restores its button state or label. Empty aria-busy regions gain skeleton placeholders; existing loading text, rows and populated regions remain intact. The operation owner still handles response errors and actual request cancellation.

For an explicit asynchronous operation:

~~~js
const loading = CelandineLoading.begin({
  button: submitButton,
  target: resultsContainer,
  message: 'Loading your records...'
});
try {
  // Keep the module's existing request, validation and response handling here.
} finally {
  loading.end();
}
~~~

The fullscreen option explicitly requests the shared full-screen component. loading.update(message) changes its feedback. loading.fail(message) ends its indicators and displays a safe error message. CelandineLoading.reset() clears all presentation state. Skeletons appear only for empty targets; skeleton: false opts out. Add data-loading="none" to a link/form to leave its existing UI entirely in control.

After 5 and 15 seconds, full-screen feedback explains that work is still pending. After 30 seconds, the overlay/spinner is removed and a nonblocking message asks the user to await a response. It never cancels or repeats a business operation. Offline events clear active indicators and show connection feedback. Page hide, restored browser history, Escape and operation completion clean up timers and indicators. There is no initial full-screen overlay merely because images or background feeds are loading.

Run node scripts/test_loading_ui_browser.cjs. It starts a disposable loopback HTTP fixture using the real shared PHP head and repository assets, with no database access. It tests roles, themes, native navigation/submission, fast requests, background fetches, skeletons, concurrent operations, offline/error cleanup, slow feedback and reduced motion.
