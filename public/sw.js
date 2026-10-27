/*
 * Service worker aplikasi lapangan.
 *
 * Tujuannya satu: halaman /lapangan dan aset-nya tetap terbuka saat tidak ada sinyal. Data dan antrean laporan
 * disimpan aplikasi sendiri di IndexedDB; service worker ini TIDAK menyentuh permintaan API.
 *
 *  - /build/*, /icons/*, manifes: cache-first (nama berkas ber-hash, isinya tak berubah).
 *  - Navigasi ke /lapangan: jaringan dulu (maks. 5 detik), cadangan dari cache bila gagal. Respons yang dialihkan
 *    (mis. ke /masuk karena sesi habis) tidak pernah disimpan.
 *  - Semua yang lain (API, halaman lain, WebSocket): langsung ke jaringan.
 */
const VERSION = 'v1';
const ASSETS = `assets-${VERSION}`;
const PAGES = `pages-${VERSION}`;
const OFFLINE_PAGES = ['/lapangan'];
const NETWORK_TIMEOUT_MS = 5000;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            for (const key of await caches.keys()) {
                if (key !== ASSETS && key !== PAGES) await caches.delete(key);
            }
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'CACHE_URLS') event.waitUntil(cacheUrls(event.data.urls ?? []));
});

/** Halaman memberi tahu aset apa saja yang dipakainya, supaya siap offline sejak kunjungan pertama. */
async function cacheUrls(urls) {
    const cache = await caches.open(ASSETS);

    for (const url of urls) {
        if (!isStatic(new URL(url, self.location.origin).pathname)) continue;

        try {
            if (!(await cache.match(url))) await cache.add(url);
        } catch {
            // Gagal satu aset tidak boleh menggagalkan yang lain.
        }
    }
}

const isStatic = (path) => path.startsWith('/build/') || path.startsWith('/icons/') || path === '/manifest.webmanifest';

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (isStatic(url.pathname)) {
        event.respondWith(cacheFirst(request));
    } else if (request.mode === 'navigate' && OFFLINE_PAGES.includes(url.pathname)) {
        event.respondWith(networkFirstPage(request, url.pathname));
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(ASSETS);
    const hit = await cache.match(request);
    if (hit) return hit;

    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());

    return response;
}

async function networkFirstPage(request, path) {
    const cache = await caches.open(PAGES);

    try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), NETWORK_TIMEOUT_MS);
        const response = await fetch(request, { signal: controller.signal });
        clearTimeout(timer);

        const isHtml = (response.headers.get('content-type') ?? '').includes('text/html');

        // Hanya halaman asli yang disimpan; pengalihan ke /masuk atau halaman galat jangan.
        if (response.ok && !response.redirected && isHtml) cache.put(path, response.clone());

        return response;
    } catch {
        const cached = await cache.match(path);

        return (
            cached ??
            new Response(
                '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>' +
                    '<body style="font:16px system-ui;margin:2rem;color:#14202b"><h1>Anda sedang offline</h1>' +
                    '<p>Halaman Lapangan belum pernah dibuka di perangkat ini. Buka sekali saat ada sinyal, setelah itu bisa dipakai tanpa sinyal.</p>',
                { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
            )
        );
    }
}
