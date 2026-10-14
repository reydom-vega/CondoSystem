# Shared loading UI

Every page using renderPortalUiHead() loads the same theme-aware component. The existing data-theme attribute and saved condo-theme preference choose the original white celandine.svg or orange celandineorange.svg. Both SVGs are preloaded. One image is displayed, without filters or artwork changes. Only the separate orange ring rotates; the logo has a small opacity animation. Reduced-motion mode stops all loading animations.

Full-screen feedback appears immediately for native same-window page transitions and form submissions. The incoming page mounts the same loader as soon as its body is parsed, including direct visits, refreshes and browser reloads, and removes it at the window load event. No artificial minimum display time or navigation delay is added. The browser Navigation API, where supported, also observes outgoing reload, Back/Forward and programmatic navigation. Other browsers still receive the incoming-page loader and native link/form feedback. JavaScript cannot draw inside a browser-owned error page or before the browser receives the application's HTML.

Prevented tab navigation, same-document history changes, downloads, exported documents, new tabs, background polling and ordinary AJAX requests do not trigger a full-screen overlay. Explicit asynchronous `begin({fullscreen:true})` calls retain their default 400 ms delay unless `delay:0` is supplied. The component never intercepts navigation, wraps fetch, disables submitters, changes form values, retries requests, or changes authorization/payment handling.

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

After 5 and 15 seconds, full-screen feedback explains that work is still pending. After 30 seconds, the overlay/spinner is removed and a nonblocking message asks the user to await a response. It never cancels or repeats a business operation. Offline events clear active indicators and show connection feedback. Page hide, restored browser history, navigation errors, Escape and operation completion clean up timers and indicators. Images loaded later by existing modules and background feeds do not reopen the initial loader.

Run node scripts/test_loading_ui_browser.cjs. It starts a disposable loopback HTTP fixture using the real shared PHP head and repository assets, with no database access. It tests roles, themes, streamed initial HTML, pending page resources, native navigation/submission, browser reload, Back navigation, fast page changes, local tabs/history, background fetches, skeletons, concurrent operations, offline/error cleanup, slow feedback and reduced motion.
