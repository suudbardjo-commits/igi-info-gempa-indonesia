import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { echo } from '../echo';

async function getJson(path, signal) {
    const res = await fetch(`/api/v1/${path}`, { headers: { Accept: 'application/json' }, signal });
    const body = await res.json().catch(() => null);
    if (!res.ok || body?.status === 'error') throw new Error(body?.error?.message ?? `HTTP ${res.status}`);

    return body;
}

/**
 * Data beranda: gempa (saringan waktu dan magnitudo), posko, statistik, peringatan dini, dan berita dari API Laravel.
 * Hanya hasil permintaan terakhir yang dipakai. Data awal dari server (Inertia) langsung ditampilkan sebelum permintaan selesai.
 */
export function useHomeData({ initialEvents = [], initialCamps = [] } = {}) {
    const hours = ref(168);
    const minMag = ref(0);
    const events = shallowRef(initialEvents);
    const camps = shallowRef(initialCamps);
    const stats = ref(null);
    const alerts = ref(null); // { ok, count, first }
    const news = ref([]);
    const error = ref(null);
    const loading = ref(true);
    const loadedAt = ref(null);
    let token = 0;

    async function load() {
        const mine = ++token;
        loading.value = true;
        error.value = null;
        const q = new URLSearchParams({ hours: hours.value, limit: 300 });
        if (minMag.value > 0) q.set('min_magnitude', minMag.value);
        const [e, c, s, a, n] = await Promise.allSettled([getJson(`disasters?${q}`), getJson('camps'), getJson('stats'), getJson('weather-alerts'), getJson('news?limit=3')]);
        if (mine !== token) return;
        if (e.status === 'fulfilled') events.value = e.value.features;
        if (c.status === 'fulfilled') camps.value = c.value.features;
        if (s.status === 'fulfilled') stats.value = s.value;
        alerts.value = a.status === 'fulfilled' ? { ok: true, count: a.value.count, first: a.value.alerts?.[0] ?? null } : { ok: false, count: 0, first: null };
        if (n.status === 'fulfilled') news.value = n.value.items ?? [];
        if (e.status === 'rejected') error.value = e.reason?.message ?? 'Data gempa belum dapat dimuat.';
        else loadedAt.value = new Date().toISOString();
        loading.value = false;
    }

    function setHours(v) { hours.value = v; return load(); }
    function setMinMag(v) { minMag.value = v; return load(); }

    // Gempa baru dari siaran langsung (Reverb) langsung masuk daftar bila lolos saringan.
    let channelOpen = false;
    function onLive(event) {
        const ageMs = Date.now() - new Date(event.occurred_at).getTime();
        if (events.value.some((f) => f.id === event.id) || ageMs > hours.value * 3_600_000 || (event.magnitude ?? 0) < minMag.value) return;
        const feature = { type: 'Feature', id: event.id, geometry: { type: 'Point', coordinates: [event.longitude, event.latitude] }, properties: { ...event, is_verified: true } };
        events.value = [feature, ...events.value];
    }

    onMounted(() => {
        load();
        try { echo.channel('public-disasters').listen('.earthquake.detected', onLive); channelOpen = true; } catch { /* tanpa siaran langsung */ }
    });
    onBeforeUnmount(() => { token++; if (channelOpen) { try { echo.leave('public-disasters'); } catch { /* sudah ditutup */ } } });

    return { hours, minMag, events, camps, stats, alerts, news, error, loading, loadedAt, reload: load, setHours, setMinMag };
}
