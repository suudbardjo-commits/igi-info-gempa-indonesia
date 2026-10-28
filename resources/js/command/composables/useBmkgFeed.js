import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { fetchFeed, mergeQuakes, parseFeed } from '../lib/bmkg';

/**
 * Polling umpan InaTEWS tiap `interval` ms. Gempa baru dideteksi dengan membandingkan field DateTime (+ koordinat)
 * autogempa dengan poll sebelumnya. Data terakhir yang berhasil dipertahankan bila poll gagal (status 'error', `stale`).
 */
export function useBmkgFeed({ interval = 60_000, onNewQuake = null } = {}) {
    const latest = shallowRef(null); // autogempa
    const recent = shallowRef([]); // gempaterkini (M 5.0+)
    const felt = shallowRef([]); // gempadirasakan
    const status = ref('loading'); // loading | ok | error
    const error = ref(null);
    const via = ref(null); // 'langsung' | 'proksi'
    const stale = ref(false);
    const lastOkAt = ref(null);
    const lastCheckAt = ref(null);
    const newQuake = shallowRef(null);

    const quakes = computed(() => mergeQuakes(latest.value ? [latest.value] : [], recent.value, felt.value));

    let timer = null;
    let controller = null;
    let previousKey = null;

    async function poll() {
        controller?.abort(); // permintaan tertinggal dibatalkan; hanya hasil poll terbaru yang dipakai
        controller = new AbortController();
        const { signal } = controller;
        lastCheckAt.value = new Date();

        const [a, t, d] = await Promise.allSettled(['autogempa', 'gempaterkini', 'gempadirasakan'].map((f) => fetchFeed(f, signal)));
        if (signal.aborted) return;

        // autogempa adalah umpan utama; dua lainnya melengkapi dan boleh gagal sendiri-sendiri.
        if (a.status === 'rejected') {
            status.value = 'error';
            error.value = a.reason?.message ?? 'Data BMKG tidak dapat diambil';
            stale.value = latest.value !== null;

            return;
        }

        const fresh = parseFeed(a.value.data, 'terbaru')[0] ?? null;
        if (t.status === 'fulfilled') recent.value = parseFeed(t.value.data, 'terkini');
        if (d.status === 'fulfilled') felt.value = parseFeed(d.value.data, 'dirasakan');

        if (fresh) {
            if (previousKey !== null && fresh.key !== previousKey) {
                newQuake.value = fresh;
                onNewQuake?.(fresh);
            }
            previousKey = fresh.key;
            // Identitas objek dipertahankan bila kejadian sama, supaya peta dan simulasi tidak diulang tiap poll.
            if (latest.value?.key !== fresh.key) latest.value = fresh;
        }

        via.value = a.value.via;
        stale.value = a.value.stale;
        status.value = 'ok';
        error.value = t.status === 'rejected' && d.status === 'rejected' ? 'Umpan pendukung (gempa M5+, dirasakan) tidak tersedia' : null;
        lastOkAt.value = new Date();
    }

    function onVisible() {
        if (!document.hidden) poll();
    }

    onMounted(() => {
        poll();
        timer = setInterval(() => { if (!document.hidden) poll(); }, interval);
        document.addEventListener('visibilitychange', onVisible);
    });

    onBeforeUnmount(() => {
        clearInterval(timer);
        controller?.abort();
        document.removeEventListener('visibilitychange', onVisible);
    });

    return { latest, recent, felt, quakes, status, error, via, stale, lastOkAt, lastCheckAt, newQuake, refresh: poll };
}
