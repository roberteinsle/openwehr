// openwehr app: offline page when there is no network, and push notifications.
// Pages are always loaded from the network. Only the offline page and the look-up pages below are cached:
// each time such a page loads, its copy and its scripts and styles (Vite build, Livewire) are renewed, so
// it can be read without network (Gefahrstoffe at the Einsatzstelle).
const CACHE = 'openwehr-v2';
const OFFLINE = '/offline';
const OFFLINE_PAGES = ['/gefahrstoffe'];

const isAsset = (path) => path.startsWith('/build/') || path.startsWith('/livewire');

// Keep the page and the assets it references; drop assets of older builds
async function keepPage(path, response) {
    const cache = await caches.open(CACHE);
    const html = await response.clone().text();
    const assets = [...html.matchAll(/(?:src|href)="([^"]+)"/g)].map((m) => new URL(m[1].replaceAll('&amp;', '&'), self.location.origin))
        .filter((u) => u.origin === self.location.origin && isAsset(u.pathname)).map((u) => u.href);
    await cache.put(path, response);
    await Promise.all(assets.map((url) => cache.match(url).then((hit) => hit || cache.add(url)).catch(() => null)));
    const wanted = new Set(assets);
    for (const request of await cache.keys()) {
        if (isAsset(new URL(request.url).pathname) && !wanted.has(request.url)) {
            await cache.delete(request);
        }
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' }))));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('openwehr-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (request.mode === 'navigate') {
        const page = OFFLINE_PAGES.find((path) => url.pathname === path);
        event.respondWith(fetch(request).then((response) => {
            if (page && response.ok && !response.redirected) {
                event.waitUntil(keepPage(page, response.clone()));
            }
            return response;
        }).catch(async () => (page && await caches.match(page)) || caches.match(OFFLINE)));
        return;
    }
    // Scripts and styles from the network; the cached copy only when it is gone
    if (isAsset(url.pathname)) {
        event.respondWith(fetch(request).catch(() => caches.match(request).then((hit) => hit || Response.error())));
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'openwehr', {
        body: data.body || '',
        icon: '/images/icon-192.png',
        badge: '/images/icon-192.png',
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((w) => w.url === url);
        return open ? open.focus() : self.clients.openWindow(url);
    }));
});
