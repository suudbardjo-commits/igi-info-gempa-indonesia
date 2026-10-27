// Uji logika service worker (public/sw.js) dengan memuatnya apa adanya ke lingkungan tiruan: CacheStorage di memori,
// fetch yang bisa diatur, dan event fetch buatan. Ini menguji aturan caching, bukan integrasi dengan peramban.
//
// Jalankan: npm run test:sw

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const ORIGIN = 'http://localhost:8000';
const SOURCE = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');

const keyOf = (req) => (typeof req === 'string' ? new URL(req, ORIGIN).href : req.url);

/** CacheStorage minimal di memori. */
function makeCaches() {
    const stores = new Map();

    const open = async (name) => {
        if (!stores.has(name)) stores.set(name, new Map());
        const store = stores.get(name);

        return {
            match: async (req) => store.get(keyOf(req))?.clone(),
            put: async (req, res) => void store.set(keyOf(req), res),
            add: async (url) => {
                const res = await fetchRef.impl(url);
                if (!res.ok) throw new TypeError('add gagal');
                store.set(keyOf(url), res);
            },
        };
    };

    return {
        stores,
        open,
        keys: async () => [...stores.keys()],
        delete: async (name) => stores.delete(name),
    };
}

const fetchRef = { impl: async () => new Response('x') };

/** Muat sw.js; `overrides` mengganti konstanta di kode sumber (mis. waktu tunggu jaringan). */
function loadWorker({ fetchImpl, overrides = [] } = {}) {
    let code = SOURCE;
    for (const [from, to] of overrides) code = code.replace(from, to);

    const listeners = {};
    const caches = makeCaches();
    fetchRef.impl = fetchImpl ?? (async () => new Response('x'));

    const self = {
        location: { origin: ORIGIN },
        addEventListener: (type, fn) => (listeners[type] = fn),
        skipWaiting: () => {},
        clients: { claim: async () => {} },
    };

    vm.runInContext(code, vm.createContext({ self, caches, fetch: (...a) => fetchRef.impl(...a), Response, URL, AbortController, setTimeout, clearTimeout }));

    return { listeners, caches };
}

/** Kirim event fetch; mengembalikan respons dari respondWith, atau undefined bila service worker membiarkannya. */
async function dispatchFetch(listeners, { url, method = 'GET', mode = 'cors' }) {
    let handled;
    listeners.fetch({ request: { url: url.startsWith('http') ? url : ORIGIN + url, method, mode, headers: new Headers() }, respondWith: (p) => (handled = p) });

    return handled ? await handled : undefined;
}

const html = (body, init = {}) => new Response(body, { status: 200, headers: { 'content-type': 'text/html; charset=utf-8' }, ...init });

function redirected(response) {
    Object.defineProperty(response, 'redirected', { value: true });

    return response;
}

test('aset ber-hash: diambil dari jaringan sekali, lalu dari cache walau jaringan mati', async () => {
    let calls = 0;
    const { listeners } = loadWorker({ fetchImpl: async () => (calls++, new Response('console.log(1)', { status: 200 })) });

    const first = await dispatchFetch(listeners, { url: '/build/assets/app-abc123.js' });
    assert.equal(await first.text(), 'console.log(1)');

    fetchRef.impl = async () => { throw new TypeError('offline'); };
    const second = await dispatchFetch(listeners, { url: '/build/assets/app-abc123.js' });

    assert.equal(await second.text(), 'console.log(1)');
    assert.equal(calls, 1);
});

test('aset yang gagal diambil (404) tidak disimpan', async () => {
    const { listeners, caches } = loadWorker({ fetchImpl: async () => new Response('tidak ada', { status: 404 }) });

    const res = await dispatchFetch(listeners, { url: '/build/assets/hilang.js' });

    assert.equal(res.status, 404);
    assert.equal([...(caches.stores.get('assets-v1')?.keys() ?? [])].length, 0);
});

test('manifes dan ikon juga ikut cache-first', async () => {
    const { listeners } = loadWorker({ fetchImpl: async () => new Response('{}', { status: 200 }) });

    for (const url of ['/manifest.webmanifest', '/icons/icon-192.png']) {
        await dispatchFetch(listeners, { url });
        fetchRef.impl = async () => { throw new TypeError('offline'); };
        assert.ok(await dispatchFetch(listeners, { url }), `${url} harus tersedia offline`);
        fetchRef.impl = async () => new Response('{}', { status: 200 });
    }
});

test('API, metode selain GET, asal lain, dan halaman lain dibiarkan ke jaringan', async () => {
    const { listeners } = loadWorker();

    assert.equal(await dispatchFetch(listeners, { url: '/api/v1/sync/field-logs', method: 'POST' }), undefined);
    assert.equal(await dispatchFetch(listeners, { url: '/api/v1/field/overview' }), undefined);
    assert.equal(await dispatchFetch(listeners, { url: '/api/broadcasting/auth', method: 'POST' }), undefined);
    assert.equal(await dispatchFetch(listeners, { url: 'https://api.telegram.org/bot/x' }), undefined);
    assert.equal(await dispatchFetch(listeners, { url: '/akun', mode: 'navigate' }), undefined);
    assert.equal(await dispatchFetch(listeners, { url: '/logistik', mode: 'navigate' }), undefined);
    // /lapangan yang bukan navigasi (mis. permintaan XHR Inertia) juga tidak dicegat.
    assert.equal(await dispatchFetch(listeners, { url: '/lapangan', mode: 'cors' }), undefined);
});

test('/lapangan: online mengembalikan halaman terbaru dan menyimpannya; offline memakai salinan terakhir', async () => {
    const { listeners } = loadWorker({ fetchImpl: async () => html('<h1>Versi 1</h1>') });

    const online = await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });
    assert.equal(await online.text(), '<h1>Versi 1</h1>');

    fetchRef.impl = async () => { throw new TypeError('offline'); };
    const offline = await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });

    assert.equal(offline.status, 200);
    assert.equal(await offline.text(), '<h1>Versi 1</h1>');
});

test('/lapangan: salinan lama ditimpa saat online lagi', async () => {
    const { listeners } = loadWorker({ fetchImpl: async () => html('lama') });
    await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });

    fetchRef.impl = async () => html('baru');
    await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });

    fetchRef.impl = async () => { throw new TypeError('offline'); };
    assert.equal(await (await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' })).text(), 'baru');
});

test('/lapangan: pengalihan ke halaman masuk (sesi habis) tidak pernah disimpan', async () => {
    const { listeners, caches } = loadWorker({ fetchImpl: async () => redirected(html('<h1>Masuk</h1>')) });

    const res = await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });
    assert.equal(await res.text(), '<h1>Masuk</h1>');   // pengguna tetap melihat halaman masuk

    assert.equal(caches.stores.get('pages-v1').size, 0);

    fetchRef.impl = async () => { throw new TypeError('offline'); };
    assert.equal((await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' })).status, 503);
});

test('/lapangan: respons galat server atau bukan HTML tidak disimpan', async () => {
    let next = new Response('galat', { status: 500, headers: { 'content-type': 'text/html' } });
    const { listeners, caches } = loadWorker({ fetchImpl: async () => next });

    await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });
    next = new Response('{"a":1}', { status: 200, headers: { 'content-type': 'application/json' } });
    await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });

    assert.equal(caches.stores.get('pages-v1').size, 0);
});

test('/lapangan: offline tanpa salinan menampilkan penjelasan berbahasa Indonesia, bukan galat peramban', async () => {
    const { listeners } = loadWorker({ fetchImpl: async () => { throw new TypeError('offline'); } });

    const res = await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });
    const body = await res.text();

    assert.equal(res.status, 503);
    assert.match(body, /Anda sedang offline/);
    assert.match(body, /Buka sekali saat ada sinyal/);
});

test('/lapangan: jaringan yang menggantung dipotong lalu memakai salinan', async () => {
    const { listeners } = loadWorker({
        fetchImpl: async () => html('tersimpan'),
        overrides: [['const NETWORK_TIMEOUT_MS = 5000', 'const NETWORK_TIMEOUT_MS = 40']],
    });
    await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });

    // Jaringan "tersambung" tetapi tidak pernah menjawab sampai permintaan dibatalkan.
    fetchRef.impl = (_url, { signal }) => new Promise((_, reject) => signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))));

    const started = Date.now();
    const res = await dispatchFetch(listeners, { url: '/lapangan', mode: 'navigate' });

    assert.equal(await res.text(), 'tersimpan');
    assert.ok(Date.now() - started < 1000, 'tidak boleh menunggu lama');
});

test('aktivasi menghapus cache versi lama tetapi menyimpan yang sekarang', async () => {
    const { listeners, caches } = loadWorker();
    await caches.open('assets-v1');
    await caches.open('pages-v1');
    await caches.open('assets-v0');
    await caches.open('workbox-lama');

    let finished;
    listeners.activate({ waitUntil: (p) => (finished = p) });
    await finished;

    assert.deepEqual((await caches.keys()).sort(), ['assets-v1', 'pages-v1']);
});

test('CACHE_URLS hanya menyimpan aset statis yang dikenal dan mengabaikan yang gagal', async () => {
    const seen = [];
    const { listeners, caches } = loadWorker({
        fetchImpl: async (url) => {
            seen.push(new URL(url, ORIGIN).pathname);

            return String(url).includes('rusak') ? new Response('', { status: 500 }) : new Response('ok');
        },
    });

    let finished;
    listeners.message({
        data: { type: 'CACHE_URLS', urls: ['/build/assets/app-1.js', '/build/assets/rusak.js', '/api/v1/rahasia', '/icons/icon-192.png', '/manifest.webmanifest', '/akun'] },
        waitUntil: (p) => (finished = p),
    });
    await finished;

    const cached = [...caches.stores.get('assets-v1').keys()].map((k) => new URL(k).pathname).sort();

    assert.deepEqual(cached, ['/build/assets/app-1.js', '/icons/icon-192.png', '/manifest.webmanifest']);
    assert.ok(!seen.includes('/api/v1/rahasia') && !seen.includes('/akun'), 'URL non-statis tidak boleh diambil');
});
