(() => {
  const config = {
    apiBase: '/api',
    homeProfileImageSizes: '(max-width: 719px) calc(50vw - .75rem - 2px), (max-width: 1049px) calc(33.333333vw - 1.4rem - 2px), min(calc(25vw - 1.15rem - 2px), calc(305px - .4rem - 2px))',
    currencyRates: {
      USD: 1,
      EUR: 0.92,
      GBP: 0.78
    }
  };
  window.__CHAMBRE_ROSE_CONFIG__ = config;

  (() => {
    const RUNTIME_CSS = `
      .catalog-switch,
      nav.catalog-switch.panel {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 0.55rem !important;
        padding: 0.5rem !important;
        width: 100% !important;
        max-width: none !important;
        justify-content: stretch !important;
        border: 1px solid rgba(140, 4, 52, 0.13) !important;
        border-radius: 14px !important;
        background: rgba(255, 253, 252, 0.96) !important;
        box-shadow: 0 7px 20px rgba(90, 35, 49, 0.06) !important;
      }
      .catalog-switch::after {
        display: none !important;
      }
      .catalog-switch a,
      nav.catalog-switch.panel a {
        display: flex !important;
        width: 100% !important;
        min-width: 0 !important;
        flex: 1 1 auto !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 0.48rem !important;
        min-height: 3rem !important;
        border-radius: 10px !important;
        border: 1px solid rgba(140, 4, 52, 0.11) !important;
        background: linear-gradient(180deg, #fffcfd 0%, #fdf2f4 100%) !important;
        color: #8c0434 !important;
        font-weight: 700 !important;
      }
      .catalog-switch a:not(.active) > span {
        background: rgba(184, 20, 58, 0.11) !important;
        color: #8c0434 !important;
      }
      .catalog-switch a.active,
      .catalog-switch a.active:visited,
      .catalog-switch a.active:hover,
      .catalog-switch a.active:focus-visible,
      nav.catalog-switch.panel a.active {
        border-color: #8c0434 !important;
        background: linear-gradient(180deg, #c61a42 0%, #8c0434 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 7px 18px rgba(140, 4, 52, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.24) !important;
      }
      .catalog-switch a.active span {
        background: rgba(255, 255, 255, 0.22) !important;
        color: #ffffff !important;
      }
      .search-panel,
      form.search-panel.panel,
      .filter-panel {
        width: 100% !important;
        max-width: none !important;
        margin-inline: 0 !important;
        padding: 0.75rem !important;
        border: 1px solid rgba(140, 4, 52, 0.13) !important;
        border-radius: 14px !important;
        background: rgba(255, 253, 252, 0.96) !important;
        box-shadow: 0 7px 20px rgba(90, 35, 49, 0.06) !important;
      }
      .search-row,
      .search-line {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: center !important;
        gap: 0.55rem !important;
      }
      .search-row .search-input,
      .search-line label {
        min-height: 2.75rem !important;
        border-radius: 999px !important;
        padding-inline: 0.9rem !important;
        border: 1px solid rgba(140, 4, 52, 0.18) !important;
        background: #ffffff !important;
      }
      .search-row .btn.search-submit,
      .search-line .btn {
        min-height: 2.75rem !important;
        border-radius: 999px !important;
        padding: 0.5rem 1.2rem !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.45rem !important;
      }
      .search-row .search-submit > i {
        display: inline-block !important;
      }
      .search-row .filter-toggle,
      .filter-panel .filters-toggle {
        grid-column: 1 / -1 !important;
        display: flex !important;
        width: 100% !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 0.5rem !important;
        min-height: 2.35rem !important;
        margin-top: 0.25rem !important;
        padding: 0.55rem 0.25rem 0.1rem !important;
        border: 0 !important;
        border-top: 1px solid #f0d8dc !important;
        border-radius: 0 !important;
        background: transparent !important;
        color: #8c0434 !important;
        font-weight: 800 !important;
      }
      .category-rail.panel {
        display: flex !important;
        align-items: stretch !important;
        width: 100% !important;
        max-width: none !important;
        gap: 0.5rem !important;
        padding: 0.55rem 0.65rem !important;
      }
      .category-rail button {
        flex: 1 1 5.5rem !important;
        border: 1px solid rgba(140, 4, 52, 0.11) !important;
        border-radius: 12px !important;
        background: linear-gradient(180deg, #fffdfd 0%, #fbf1f3 100%) !important;
        color: #8c0434 !important;
      }
      .category-rail button.active {
        border-color: #8c0434 !important;
        background: linear-gradient(180deg, #c61a42 0%, #8c0434 100%) !important;
        color: #ffffff !important;
      }
      .category-rail > .category-label {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.4rem !important;
        padding: 0.45rem 0.65rem !important;
        border: 1px solid rgba(140, 4, 52, 0.12) !important;
        border-radius: 11px !important;
        background: linear-gradient(180deg, #fff8f9 0%, #fdf0f3 100%) !important;
        color: #8c0434 !important;
        font-weight: 800 !important;
      }
      .btn-primary,
      button.btn-primary,
      a.btn-primary,
      .pay-button,
      .account-trigger {
        border: 1px solid rgba(140, 4, 52, 0.85) !important;
        background: linear-gradient(180deg, #c91942 0%, #9e0b37 54%, #8c0434 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 6px 18px rgba(140, 4, 52, 0.22), inset 0 1px 0 rgba(255, 240, 242, 0.28) !important;
      }
    `;

    function ensureRuntimeThemeLast() {
      if (!document.head) return;
      let styleEl = document.getElementById('cr-brand-runtime-theme');
      if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'cr-brand-runtime-theme';
        styleEl.textContent = RUNTIME_CSS;
        document.head.appendChild(styleEl);
      } else if (document.head.lastElementChild !== styleEl) {
        document.head.appendChild(styleEl);
      }
    }

    ensureRuntimeThemeLast();
    if (document.head && typeof MutationObserver !== 'undefined') {
      const headObserver = new MutationObserver(() => {
        ensureRuntimeThemeLast();
      });
      headObserver.observe(document.head, { childList: true });
    }

    let loaderEl = null;
    let hideTimer = null;
    let safetyTimer = null;
    let shownAt = 0;

    function ensureLoader() {
      if (loaderEl) return loaderEl;
      if (!document.body) return null;
      loaderEl = document.getElementById('cr-brand-loader');
      if (!loaderEl) {
        loaderEl = document.createElement('div');
        loaderEl.id = 'cr-brand-loader';
        loaderEl.className = 'cr-brand-loader';
        loaderEl.setAttribute('role', 'status');
        loaderEl.setAttribute('aria-live', 'polite');
        loaderEl.setAttribute('aria-label', 'Loading Chambre Rose');
        loaderEl.innerHTML =
          '<div class="cr-brand-loader-medallion">' +
            '<span class="cr-brand-loader-halo" aria-hidden="true"></span>' +
            '<span class="cr-brand-loader-ring" aria-hidden="true"></span>' +
            '<img class="cr-brand-loader-logo" src="/assets/brand-logo-84.webp" width="50" height="60" alt="">' +
          '</div>' +
          '<div class="cr-brand-loader-copy">' +
            '<strong class="cr-brand-loader-title">Chambre Rose</strong>' +
            '<span class="cr-brand-loader-bar" aria-hidden="true"></span>' +
          '</div>';
        document.body.appendChild(loaderEl);
      }
      return loaderEl;
    }

    function showLoader(maxMs = 1400) {
      const el = ensureLoader();
      if (!el) return;
      clearTimeout(hideTimer);
      clearTimeout(safetyTimer);
      shownAt = performance.now();
      el.classList.add('is-active');
      safetyTimer = setTimeout(() => hideLoader(0), maxMs);
    }

    function hideLoader(minDurationMs = 420) {
      if (!loaderEl) return;
      const elapsed = performance.now() - shownAt;
      const remaining = Math.max(0, minDurationMs - elapsed);
      clearTimeout(hideTimer);
      hideTimer = setTimeout(() => {
        clearTimeout(safetyTimer);
        if (loaderEl) loaderEl.classList.remove('is-active');
      }, remaining);
    }

    function bootLoader() {
      ensureRuntimeThemeLast();
      showLoader(1500);
      if (document.readyState === 'complete') {
        hideLoader(480);
      } else {
        window.addEventListener('load', () => hideLoader(480), { once: true });
      }
    }

    if (document.body) {
      bootLoader();
    } else {
      document.addEventListener('DOMContentLoaded', bootLoader, { once: true });
    }

    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      if (target.closest('.quick-profile-access')) {
        showLoader(1600);
        hideLoader(620);
      }
    }, true);

    document.addEventListener('submit', (event) => {
      const form = event.target instanceof HTMLFormElement ? event.target : null;
      if (form && !form.classList.contains('search-panel')) {
        showLoader(1600);
        hideLoader(560);
      }
    }, true);

    let lastPathname = window.location.pathname;
    const origPushState = history.pushState;
    history.pushState = function (...args) {
      const result = origPushState.apply(this, args);
      if (window.location.pathname !== lastPathname) {
        lastPathname = window.location.pathname;
        showLoader(1100);
        hideLoader(380);
      }
      return result;
    };

    window.addEventListener('popstate', () => {
      if (window.location.pathname !== lastPathname) {
        lastPathname = window.location.pathname;
        showLoader(1100);
        hideLoader(380);
      }
    });
  })();

  if (window.location.pathname !== '/' && window.location.pathname !== '/index.html') return;

  let location = null;
  try {
    const stored = JSON.parse(window.localStorage.getItem('chambre_rose_browse_location') || 'null');
    if (stored && typeof stored.city === 'string' && typeof stored.country === 'string') {
      location = {
        city: stored.city.trim(),
        region: typeof stored.region === 'string' ? stored.region.trim() : '',
        country: stored.country.trim()
      };
    }
  } catch {
    location = null;
  }

  const locationKey = [location?.city, location?.region, location?.country]
    .map((value) => (value || '').toLowerCase())
    .join('|');
  const params = new URLSearchParams({ type: 'ESCORT' });
  if (location?.city) params.set('nearCity', location.city);
  if (location?.region) params.set('nearRegion', location.region);
  if (location?.country) params.set('nearCountry', location.country);
  params.set('pageSize', '10');
  params.set('includeTotal', 'false');

  const apiBase = String(config.apiBase || '/api').replace(/\/+$/, '');
  const promise = window.fetch(`${apiBase}/listings?${params.toString()}`, {
    credentials: 'omit',
    headers: { Accept: 'application/json' }
  }).then((response) => response.ok ? response.json() : null).catch(() => null);

  const prefetch = { locationKey, promise, imagePreloads: [] };
  window.__CHAMBRE_ROSE_HOME_LISTINGS__ = prefetch;
  void promise.then((payload) => {
    const items = Array.isArray(payload?.items) ? payload.items : [];
    const visibleProfiles = items.filter((item) => item?.vipActive === true
      && typeof (item.cardCoverUrl || item.profileImageUrl) === 'string'
      && (item.cardCoverUrl || item.profileImageUrl).trim() !== '').slice(0, 4);
    prefetch.imagePreloads = visibleProfiles.map((item, index) => {
      const image = new Image();
      image.loading = 'lazy';
      image.decoding = 'async';
      image.fetchPriority = index < 4 ? 'high' : 'auto';
      image.dataset.profileId = String(item.id ?? item.userId);
      image.sizes = config.homeProfileImageSizes;
      image.src = item.cardCoverUrl || item.profileImageUrl;
      const srcSet = item.cardCoverUrl ? item.cardCoverSrcSet : item.profileImageSrcSet;
      if (typeof srcSet === 'string' && srcSet.trim()) {
        image.srcset = srcSet.split(',').map(candidate => candidate.trim()).join(', ');
      }
      image.loading = 'eager';
      return image;
    });
  });
})();
