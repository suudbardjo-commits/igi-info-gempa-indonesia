import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { reveal } from './lib/reveal';
import { useTextSize } from './composables/useTextSize';

// Service worker agar halaman Lapangan tetap terbuka tanpa sinyal. Hanya di build produksi: di mode dev
// (npm run dev) modul Vite berubah terus dan tidak boleh di-cache.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', async () => {
        try {
            await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            const registration = await navigator.serviceWorker.ready;

            // Beri tahu service worker aset yang baru dipakai halaman ini, supaya kunjungan pertama pun siap offline.
            const urls = performance
                .getEntriesByType('resource')
                .map((entry) => new URL(entry.name))
                .filter((url) => url.origin === location.origin && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')))
                .map((url) => url.pathname);

            registration.active?.postMessage({ type: 'CACHE_URLS', urls: [...new Set([...urls, '/manifest.webmanifest', '/icons/icon-192.png'])] });
        } catch {
            // Tanpa service worker aplikasi tetap jalan, hanya tidak bisa dibuka offline.
        }
    });
}

createInertiaApp({
    // Bilah kemajuan saat pindah halaman, berwarna sian jejak, supaya terlihat ada proses yang berjalan.
    progress: { color: '#6fe0ee', delay: 120, showSpinner: false },
    title: (title) => (title ? `${title} - IGI` : 'IGI - Info Gempa Indonesia'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        // Ukuran teks pilihan pengguna diterapkan sebelum halaman tergambar, supaya tidak terlihat melompat.
        useTextSize().load();

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .directive('reveal', reveal)
            .mount(el);
    },
});
