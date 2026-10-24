'use strict';

const CANONICAL_ORIGIN = 'https://www.chambre-rose.com';

function canonicalUrl(source) {
  const url = new URL(source);
  return `${CANONICAL_ORIGIN}${url.pathname}${url.search}${url.hash}`;
}

self.addEventListener('install', () => {
  void self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const cacheNames = await caches.keys();
    await Promise.all(cacheNames
      .filter((name) => name.startsWith('ngsw:'))
      .map((name) => caches.delete(name)));

    await self.clients.claim();
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    await Promise.all(windows.map((client) => client.navigate(canonicalUrl(client.url))));
    await self.registration.unregister();
  })());
});

self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return;
  event.respondWith(Promise.resolve(Response.redirect(canonicalUrl(event.request.url), 302)));
});
