// Klien data terbuka BMKG InaTEWS (data.bmkg.go.id). Peramban mencoba langsung (BMKG mengirim CORS "*"); bila gagal
// (CORS, jaringan, pemblokiran) jatuh ke proksi server IGI /api/v1/bmkg/{umpan}. Hasil dinormalisasi ke satu bentuk. Tiap jalur dibatasi 3 detik (proksi 6 detik) agar cadangan USGS cepat dipakai.
import { parseCoordinates } from './geo.js';
import { BMKG_TIMEOUT_MS, fetchWithTimeout } from './ingest.js';
import { parseDepthKm, parseMmi } from './seismic.js';

export const BMKG_BASE = 'https://data.bmkg.go.id/DataMKG/TEWS';
export const FEEDS = ['autogempa', 'gempaterkini', 'gempadirasakan'];

function getJson(url, signal, ms) {
    // Tanpa header khusus: Accept: application/json ditolak BMKG (403) dan memicu preflight CORS.
    return fetchWithTimeout(url, ms, signal);
}

/**
 * Ambil satu umpan. Mengembalikan { data, via: 'langsung' | 'proksi', stale }.
 * Melempar Error bila kedua jalur gagal.
 */
export async function fetchFeed(name, signal) {
    try {
        return { data: await getJson(`${BMKG_BASE}/${name}.json`, signal, BMKG_TIMEOUT_MS), via: 'langsung', stale: false };
    } catch (direct) {
        if (signal?.aborted) throw direct;
        const body = await getJson(`/api/v1/bmkg/${name}`, signal, BMKG_TIMEOUT_MS * 2);
        if (body?.status !== 'ok') throw new Error(body?.error?.message ?? 'Proksi BMKG gagal');

        return { data: body.data, via: 'proksi', stale: Boolean(body.is_stale) };
    }
}

/** Satu entri BMKG menjadi kejadian bersih; null bila koordinat/magnitudo tidak sah (koordinat dari "Coordinates", bukan teks Lintang/Bujur). */
export function normalizeQuake(raw, jenis) {
    if (!raw || typeof raw !== 'object') return null;
    const c = parseCoordinates(raw.Coordinates);
    const mag = Number.parseFloat(raw.Magnitude);
    const time = new Date(raw.DateTime);
    if (!c || !Number.isFinite(mag) || Number.isNaN(time.getTime())) return null;
    const felt = raw.Dirasakan && raw.Dirasakan !== '-' ? String(raw.Dirasakan) : null;

    return {
        key: `${raw.DateTime}|${raw.Coordinates}`,
        jenis, // 'terbaru' | 'terkini' | 'dirasakan'
        time,
        lat: c.lat,
        lng: c.lng,
        mag,
        depthKm: parseDepthKm(raw.Kedalaman),
        region: String(raw.Wilayah ?? '').trim() || 'Wilayah tidak disebutkan',
        tsunami: raw.Potensi ? String(raw.Potensi) : null,
        felt,
        mmi: parseMmi(felt),
        shakemapUrl: raw.Shakemap ? `${BMKG_BASE}/${encodeURIComponent(raw.Shakemap)}` : null,
        source: 'bmkg',
        validation: 'official',
    };
}

/** { Infogempa: { gempa: obj | [obj] } } menjadi daftar kejadian. */
export function parseFeed(payload, jenis) {
    const g = payload?.Infogempa?.gempa;
    const list = Array.isArray(g) ? g : g ? [g] : [];

    return list.map((r) => normalizeQuake(r, jenis)).filter(Boolean);
}

/** Gabungan tanpa duplikat (kunci waktu+koordinat), terbaru dahulu; entri dengan Shakemap/MMI melengkapi yang kosong. */
export function mergeQuakes(...lists) {
    const map = new Map();
    for (const q of lists.flat()) {
        const prev = map.get(q.key);
        map.set(q.key, prev ? { ...prev, felt: prev.felt ?? q.felt, mmi: prev.mmi ?? q.mmi, shakemapUrl: prev.shakemapUrl ?? q.shakemapUrl, jenis: prev.jenis === 'terbaru' ? 'terbaru' : q.jenis } : q);
    }

    return [...map.values()].sort((a, b) => b.time - a.time);
}
