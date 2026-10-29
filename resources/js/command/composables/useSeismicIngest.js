import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { EMSC_WS_URL, backoffMs, fetchUsgsFallback, matchOfficial, normalizeEmsc } from '../lib/ingest';

const KEEP_MS = 3 * 3600_000; // kejadian EMSC lebih tua dari ini dibuang dari memori
const MAX_EMSC = 60;
const USGS_RETRY_MS = 120_000;

/**
 * Ingest multi-sumber di atas umpan BMKG:
 *  - EMSC WebSocket: gempa Indonesia terdeteksi lebih awal, ditandai "menunggu validasi BMKG" (validation = 'pending')
 *    sampai ada pasangan resmi (waktu +/- 3 menit, jarak <= 150 km).
 *  - BMKG InaTEWS (feed dari useBmkgFeed): otoritas resmi.
 *  - USGS: dimuat hanya saat umpan BMKG gagal; ditandai 'fallback'.
 * Semua kegagalan jaringan ditelan dan tercermin di `sources`; tidak pernah melempar ke komponen.
 *
 * @param feed hasil useBmkgFeed
 * @param callbacks onDetected(q): gempa baru dari EMSC yang belum punya pasangan resmi
 *                  onValidated(official, via): gempa resmi baru atau pasangan resmi bagi kejadian EMSC
 */
export function useSeismicIngest(feed, { onDetected = null, onValidated = null } = {}) {
    const emsc = shallowRef([]);
    const usgs = shallowRef([]);
    const emscState = ref('connecting'); // connecting | open | closed | unsupported
    const usgsState = ref('idle'); // idle | loading | ok | error

    let ws = null;
    let attempt = 0;
    let retryTimer = null;
    let usgsTimer = null;
    let stopped = false;
    const detected = new Set(); // kunci EMSC yang sudah diumumkan
    const validatedKeys = new Set(); // pasangan EMSC-resmi yang sudah diumumkan

    const official = computed(() => feed.quakes.value);
    const match = computed(() => matchOfficial(emsc.value, official.value));
    const pending = computed(() => match.value.pending);

    // Gabungan untuk daftar/peta: resmi + EMSC yang masih menunggu + USGS (hanya bila BMKG gagal dan belum ada pasangan resmi).
    const quakes = computed(() => {
        const base = [...official.value, ...pending.value];
        if (feed.status.value === 'error') {
            for (const u of usgs.value) {
                if (!base.some((b) => Math.abs(b.time - u.time) <= 180_000 && Math.abs(b.lat - u.lat) < 1.5 && Math.abs(b.lng - u.lng) < 1.5)) base.push(u);
            }
        }

        return base.sort((a, b) => b.time - a.time);
    });

    const latest = computed(() => quakes.value[0] ?? null);

    function upsertEmsc(q) {
        const rest = emsc.value.filter((e) => e.key !== q.key && Date.now() - e.time < KEEP_MS);
        emsc.value = [q, ...rest].sort((a, b) => b.time - a.time).slice(0, MAX_EMSC);
    }

    function onMessage(ev) {
        const q = normalizeEmsc(ev.data);
        if (!q) return;
        const isNew = !emsc.value.some((e) => e.key === q.key);
        upsertEmsc(q);
        // Hanya kejadian yang masih segar (< 30 menit) yang diumumkan; EMSC kadang mengirim ulang kejadian lama.
        if (isNew && !detected.has(q.key) && Date.now() - q.time < 30 * 60_000 && !match.value.matched.has(q.key)) {
            detected.add(q.key);
            onDetected?.(q);
        }
    }

    function connect() {
        if (stopped) return;
        if (typeof WebSocket === 'undefined') { emscState.value = 'unsupported'; return; }
        emscState.value = 'connecting';
        try {
            ws = new WebSocket(EMSC_WS_URL);
        } catch {
            scheduleReconnect();

            return;
        }
        ws.onopen = () => { attempt = 0; emscState.value = 'open'; };
        ws.onmessage = onMessage;
        ws.onerror = () => { /* onclose menyusul dan menjadwalkan sambung ulang */ };
        ws.onclose = () => { emscState.value = 'closed'; scheduleReconnect(); };
    }

    function scheduleReconnect() {
        if (stopped) return;
        clearTimeout(retryTimer);
        retryTimer = setTimeout(connect, backoffMs(attempt++));
    }

    async function loadUsgs() {
        usgsState.value = 'loading';
        try {
            usgs.value = await fetchUsgsFallback();
            usgsState.value = 'ok';
        } catch {
            usgsState.value = 'error';
        }
        // Selama BMKG masih gagal, cadangan diperbarui berkala.
        clearTimeout(usgsTimer);
        if (!stopped && feed.status.value === 'error') usgsTimer = setTimeout(loadUsgs, USGS_RETRY_MS);
    }

    // Kejadian EMSC yang akhirnya cocok dengan data resmi diumumkan sekali lewat onValidated. Kejadian resmi yang tidak
    // pernah terdeteksi EMSC diumumkan oleh pemakai lewat onNewQuake milik useBmkgFeed.
    watch(() => match.value.matched, (m) => {
        for (const [emscKey, o] of m) {
            if (validatedKeys.has(`m:${emscKey}`)) continue;
            validatedKeys.add(`m:${emscKey}`);
            if (detected.has(emscKey)) onValidated?.(o, 'emsc');
        }
    });

    watch(() => feed.status.value, (s) => {
        if (s === 'error' && usgsState.value !== 'loading') loadUsgs();
        if (s === 'ok') { clearTimeout(usgsTimer); usgsState.value = 'idle'; usgs.value = []; }
    });

    onMounted(() => {
        connect();
    });
    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(retryTimer);
        clearTimeout(usgsTimer);
        try { ws?.close(); } catch { /* sudah tertutup */ }
    });

    const sources = computed(() => ({
        emsc: emscState.value,
        bmkg: feed.status.value,
        usgs: usgsState.value,
        usgsAktif: feed.status.value === 'error' && usgs.value.length > 0,
    }));

    return { quakes, latest, pending, sources, emscQuakes: emsc };
}
