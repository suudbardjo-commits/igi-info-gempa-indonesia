import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { DYFI_LEVELS, buildReport, clusterReports, cooldownLeft, flushQueue, queueSize, submitReport } from '../lib/dyfi';

/**
 * Laporan warga DYFI: ambil titik laporan terbaru untuk peta, kirim laporan 1 ketukan, dan antre saat luring.
 * `paused` (ref) menghentikan polling di Mode Blackout. Semua kegagalan jaringan ditangani; tidak ada yang dilempar.
 */
export function useFeltReports({ paused = ref(false), interval = 90_000 } = {}) {
    const reports = shallowRef([]);
    const loadError = ref(false);
    const sending = ref(false);
    const result = ref(null); // { kind: 'terkirim' | 'antre' | 'ditolak' | 'jeda' | 'lokasi', text }
    const queued = ref(0);
    const now = ref(Date.now());

    const clusters = computed(() => clusterReports(reports.value));
    const storage = () => { try { return globalThis.localStorage; } catch { return null; } };
    let timer;
    let tick;
    let controller;

    async function load() {
        if (paused.value || document.hidden) return;
        controller?.abort();
        controller = new AbortController();
        try {
            const res = await fetch('/api/v1/felt-reports?hours=6', { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const body = await res.json();
            reports.value = Array.isArray(body.reports) ? body.reports : [];
            loadError.value = false;
        } catch (e) {
            if (e?.name !== 'AbortError') loadError.value = true;
        }
    }

    const cooldownSeconds = computed(() => {
        const s = storage();

        return s ? Math.ceil(cooldownLeft(s, now.value) / 1000) : 0;
    });

    async function retryQueue() {
        const s = storage();
        if (!s) return;
        const sent = await flushQueue({ storage: s });
        queued.value = queueSize(s);
        if (sent) { result.value = { kind: 'terkirim', text: `${sent} laporan tertunda berhasil dikirim.` }; load(); }
    }

    /** Kirim laporan. `position` = {lat, lng} atau null bila lokasi belum diaktifkan. */
    async function report(level, position, quakeKey = null) {
        const s = storage();
        if (!position) { result.value = { kind: 'lokasi', text: 'Aktifkan lokasi Anda dulu (tombol di kartu Telemetri) agar laporan bisa ditandai di peta.' }; return; }
        if (s && cooldownLeft(s) > 0) { result.value = { kind: 'jeda', text: `Laporan sudah dikirim. Tunggu ${Math.ceil(cooldownLeft(s) / 60_000)} menit sebelum melapor lagi.` }; return; }
        const payload = buildReport(level, position, { quakeKey });
        if (!payload) return;
        sending.value = true;
        const kind = await submitReport(payload, { storage: s ?? { getItem: () => null, setItem() {} } });
        sending.value = false;
        queued.value = s ? queueSize(s) : 0;
        now.value = Date.now();
        result.value = {
            terkirim: { kind, text: 'Terima kasih. Laporan Anda tercatat tanpa identitas.' },
            antre: { kind, text: 'Tidak ada koneksi. Laporan disimpan di ponsel dan dikirim otomatis saat daring.' },
            ditolak: { kind, text: 'Laporan ditolak server (terlalu sering atau tidak sah). Coba lagi nanti.' },
        }[kind];
        if (kind === 'terkirim') load();
    }

    onMounted(() => {
        const s = storage();
        queued.value = s ? queueSize(s) : 0;
        load();
        retryQueue();
        timer = setInterval(load, interval);
        tick = setInterval(() => { now.value = Date.now(); }, 5000);
        window.addEventListener('online', retryQueue);
    });
    onBeforeUnmount(() => {
        clearInterval(timer);
        clearInterval(tick);
        controller?.abort();
        window.removeEventListener('online', retryQueue);
    });

    return { reports, clusters, loadError, sending, result, queued, cooldownSeconds, report, reload: load, levels: DYFI_LEVELS };
}
