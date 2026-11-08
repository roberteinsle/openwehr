// Served as /sw.js on an alias address (APP_ALIAS_URLS) instead of the app's worker. A phone that installed the
// app while this address was the main one still has the old worker, which would answer the redirect to APP_URL
// with the "Keine Verbindung" page. This worker replaces it, deletes its caches, unregisters itself and reloads
// the open windows, which then follow the redirect to the main address.
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        await Promise.all((await caches.keys()).map((key) => caches.delete(key)));
        await self.registration.unregister();
        for (const client of await self.clients.matchAll({ type: 'window' })) {
            client.navigate(client.url);
        }
    })());
});
