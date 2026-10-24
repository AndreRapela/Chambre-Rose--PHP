(function resetLocalPreviewServiceWorker() {
  var BUILD_VERSION = 'cr7';
  var isLocalPreview = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
    && window.location.port === '4200';

  if (!isLocalPreview) return;

  window.addEventListener('pageshow', function refreshRestoredPreview(event) {
    if (!event.persisted) return;

    var restoredUrl = new URL(window.location.href);
    restoredUrl.searchParams.set('ngsw-bypass', 'true');
    restoredUrl.searchParams.set('local-sw-reset', Date.now().toString());
    window.location.replace(restoredUrl.toString());
  });

  var needsBuildRefresh = false;
  try {
    if (window.localStorage.getItem('cr_ui_build_version') !== BUILD_VERSION) {
      needsBuildRefresh = true;
      window.localStorage.setItem('cr_ui_build_version', BUILD_VERSION);
    }
  } catch (e) {}

  var swPromise = ('serviceWorker' in navigator)
    ? navigator.serviceWorker.getRegistrations()
    : Promise.resolve([]);
  var cachesPromise = ('caches' in window)
    ? caches.keys()
    : Promise.resolve([]);

  Promise.all([swPromise, cachesPromise]).then(function cleanLocalPreviewState(results) {
    var registrations = results[0];
    var cacheNames = results[1];

    var reloadAssets = needsBuildRefresh
      ? Promise.all([
          '/styles-IXSZ5RC5.css',
          '/assets/brand-theme.css',
          '/main-OSVX5Y2B.js',
          '/chunk-BALGVHJ4.js',
          '/chunk-K2OVI3U2.js',
          '/chunk-EQ3J6H3O.js',
          '/chunk-WIQ2OR2W.js',
          '/chunk-4ZBSFAP5.js',
          '/chunk-HLMKLENW.js'
        ].map(function warmAsset(url) {
          return window.fetch(url, { cache: 'reload' }).catch(function ignore() {});
        }))
      : Promise.resolve();

    return Promise.all([
      Promise.all(registrations.map(function unregister(registration) {
        return registration.unregister();
      })),
      Promise.all(cacheNames.map(function removeCache(name) {
        return caches.delete(name);
      })),
      reloadAssets
    ]).then(function reloadFromNetworkWhenNeeded() {
      var resetUrl = new URL(window.location.href);
      var isResetNavigation = resetUrl.searchParams.has('local-sw-reset');

      if ((registrations.length > 0 || cacheNames.length > 0 || needsBuildRefresh) && !isResetNavigation) {
        resetUrl.searchParams.set('ngsw-bypass', 'true');
        resetUrl.searchParams.set('local-sw-reset', Date.now().toString());
        window.location.replace(resetUrl.toString());
        return;
      }

      if (isResetNavigation) {
        resetUrl.searchParams.delete('ngsw-bypass');
        resetUrl.searchParams.delete('local-sw-reset');
        window.history.replaceState(window.history.state, '', resetUrl.toString());
      }
    });
  }).catch(function keepPreviewAvailable() {
    // The development app must remain usable if browser storage is unavailable.
  });
})();
