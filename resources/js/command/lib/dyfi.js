// Laporan warga "Did You Feel It" (DYFI): payload ringkas, antrean kirim saat luring, dan pengelompokan titik untuk peta.
// Fungsi bersifat murni atau menerima `storage`/`fetchFn` sebagai argumen supaya bisa diuji tanpa peramban.

export const DYFI_LEVELS = {
    none: { label: 'Tidak merasakan', color: '#22C55E', rank: 0 },
    light: { label: 'Getaran ringan atau sedang', color: '#EAB308', rank: 1 },
    strong: { label: 'Guncangan kuat atau merusak', color: '#EF4444', rank: 2 },
};

const QUEUE_KEY = 'igi:dyfi:antrean';
const LAST_KEY = 'igi:dyfi:terakhir';
export const COOLDOWN_MS = 10 * 60 * 1000;
const MAX_QUEUE = 20;

const round2 = (n) => Math.round(n * 100) / 100;

/** Payload ringkas: tingkat, titik dibulatkan ~1 km, waktu. Null bila tingkat atau koordinat tidak sah. */
export function buildReport(level, position, { now = new Date(), quakeKey = null } = {}) {
    if (!DYFI_LEVELS[level] || !position || !Number.isFinite(position.lat) || !Number.isFinite(position.lng)) return null;

    return { level, lat: round2(position.lat), lng: round2(position.lng), felt_at: now.toISOString(), ...(quakeKey ? { quake_key: String(quakeKey).slice(0, 80) } : {}) };
}

function read(storage, key, fallback) {
    try { return JSON.parse(storage.getItem(key)) ?? fallback; } catch { return fallback; }
}
function write(storage, key, value) {
    try { storage.setItem(key, JSON.stringify(value)); } catch { /* penyimpanan penuh atau dilarang */ }
}

/** Sisa jeda (ms) sebelum boleh melapor lagi; 0 bila boleh. */
export function cooldownLeft(storage, now = Date.now()) {
    const last = Number(read(storage, LAST_KEY, 0));

    return Math.max(0, COOLDOWN_MS - (now - last));
}

/** Kirim satu laporan. Gagal jaringan: masuk antrean dan dicoba lagi lewat flushQueue. @returns 'terkirim' | 'antre' | 'ditolak' */
export async function submitReport(payload, { fetchFn = globalThis.fetch, storage, now = Date.now() } = {}) {
    try {
        const res = await fetchFn('/api/v1/felt-reports', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) });
        if (res.status === 422 || res.status === 429) return 'ditolak';
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        write(storage, LAST_KEY, now);

        return 'terkirim';
    } catch {
        const q = read(storage, QUEUE_KEY, []);
        write(storage, QUEUE_KEY, [...q, payload].slice(-MAX_QUEUE));
        write(storage, LAST_KEY, now);

        return 'antre';
    }
}

/** Kirim ulang antrean (dipanggil saat kembali daring). Laporan yang ditolak server dibuang; yang gagal jaringan tetap. */
export async function flushQueue({ fetchFn = globalThis.fetch, storage } = {}) {
    const q = read(storage, QUEUE_KEY, []);
    if (!q.length) return 0;
    const keep = [];
    let sent = 0;
    for (const p of q) {
        try {
            const res = await fetchFn('/api/v1/felt-reports', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(p) });
            if (res.ok) sent++;
            else if (res.status !== 422 && res.status !== 429) keep.push(p);
        } catch {
            keep.push(p);
        }
    }
    write(storage, QUEUE_KEY, keep);

    return sent;
}

export const queueSize = (storage) => read(storage, QUEUE_KEY, []).length;

/**
 * Kelompokkan titik ke sel `cell` derajat (0,1 ~ 11 km). Warna kelompok = tingkat tertinggi; `count` = jumlah laporan.
 * Titik dipusatkan di rata-rata koordinat sel.
 */
export function clusterReports(points, cell = 0.1) {
    const cells = new Map();
    for (const p of points ?? []) {
        if (!DYFI_LEVELS[p.level] || !Number.isFinite(p.lat) || !Number.isFinite(p.lng)) continue;
        const k = `${Math.floor(p.lat / cell)}:${Math.floor(p.lng / cell)}`;
        const c = cells.get(k) ?? { lat: 0, lng: 0, count: 0, level: 'none', counts: { none: 0, light: 0, strong: 0 } };
        c.lat += p.lat;
        c.lng += p.lng;
        c.count += 1;
        c.counts[p.level] += 1;
        if (DYFI_LEVELS[p.level].rank > DYFI_LEVELS[c.level].rank) c.level = p.level;
        cells.set(k, c);
    }

    return [...cells.values()].map((c) => ({ ...c, lat: c.lat / c.count, lng: c.lng / c.count }));
}
