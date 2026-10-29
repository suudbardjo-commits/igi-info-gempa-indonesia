// Ingest gempa multi-sumber: EMSC (WebSocket, deteksi tercepat, belum divalidasi), BMKG InaTEWS (otoritas resmi), USGS
// (cadangan bila BMKG gagal). Fungsi di sini murni (tanpa DOM) supaya bisa diuji; koneksi ada di useSeismicIngest.js.
import { haversineKm } from './geo.js';

export const EMSC_WS_URL = 'wss://www.seismicportal.eu/standing_order/websocket';
export const USGS_HOUR_URL = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson';
export const BMKG_TIMEOUT_MS = 3000;

/** Kotak batas Indonesia (spesifikasi): Lat -11..6, Bujur 95..141. */
export const ID_BOUNDS = { south: -11, north: 6, west: 95, east: 141 };

export function inIndonesia(lat, lng) {
    return Number.isFinite(lat) && Number.isFinite(lng) && lat >= ID_BOUNDS.south && lat <= ID_BOUNDS.north && lng >= ID_BOUNDS.west && lng <= ID_BOUNDS.east;
}

/** Angka dari nilai bebas; null atau kosong menjadi NaN (Number(null) = 0 akan lolos sebagai magnitudo 0). */
const num = (v) => (v === null || v === undefined || v === '' ? Number.NaN : Number(v));

/** Magnitudo, kedalaman, dan koordinat harus angka sah; waktu harus terbaca. */
function build(base) {
    const time = new Date(base.time);
    if (![base.lat, base.lng, base.mag].every(Number.isFinite) || Number.isNaN(time.getTime())) return null;
    if (Math.abs(base.lat) > 90 || Math.abs(base.lng) > 180) return null;

    return {
        key: `${base.source}:${base.id}`,
        jenis: 'terkini',
        time,
        lat: base.lat,
        lng: base.lng,
        mag: base.mag,
        depthKm: Number.isFinite(base.depthKm) && base.depthKm >= 0 ? base.depthKm : null,
        region: String(base.region ?? '').trim() || 'Wilayah tidak disebutkan',
        tsunami: null,
        felt: null,
        mmi: null,
        shakemapUrl: null,
        source: base.source, // 'emsc' | 'usgs'
        validation: base.source === 'emsc' ? 'pending' : 'fallback',
    };
}

/**
 * Pesan WebSocket EMSC { action: 'create'|'update', data: { type: 'Feature', id, geometry, properties } } menjadi
 * kejadian. Null bila bukan di Indonesia, bukan create/update, atau tidak sah.
 */
export function normalizeEmsc(message) {
    let msg = message;
    if (typeof msg === 'string') {
        try { msg = JSON.parse(msg); } catch { return null; }
    }
    if (!msg || (msg.action !== 'create' && msg.action !== 'update')) return null;
    const p = msg.data?.properties;
    if (!p) return null;
    const lat = Number(p.lat ?? msg.data?.geometry?.coordinates?.[1]);
    const lng = Number(p.lon ?? msg.data?.geometry?.coordinates?.[0]);
    if (!inIndonesia(lat, lng)) return null;
    const q = build({ source: 'emsc', id: msg.data.id ?? p.unid, time: p.time, lat, lng, mag: num(p.mag), depthKm: num(p.depth), region: p.flynn_region });

    return q ? { ...q, action: msg.action } : null;
}

/** GeoJSON USGS (summary feed) menjadi kejadian yang jatuh di kotak Indonesia, terbaru dahulu. */
export function normalizeUsgs(geojson) {
    const feats = Array.isArray(geojson?.features) ? geojson.features : [];

    return feats
        .map((f) => build({
            source: 'usgs',
            id: f.id,
            time: f.properties?.time,
            lat: Number(f.geometry?.coordinates?.[1]),
            lng: Number(f.geometry?.coordinates?.[0]),
            mag: num(f.properties?.mag),
            depthKm: num(f.geometry?.coordinates?.[2]),
            region: f.properties?.place,
        }))
        .filter((q) => q && inIndonesia(q.lat, q.lng))
        .sort((a, b) => b.time - a.time);
}

/** Satu gempa yang sama menurut waktu (+/- 3 menit) dan lokasi (<= 150 km)? Dipakai mencocokkan EMSC dengan BMKG. */
export function sameEvent(a, b, { seconds = 180, km = 150 } = {}) {
    return Math.abs(a.time - b.time) <= seconds * 1000 && haversineKm(a.lat, a.lng, b.lat, b.lng) <= km;
}

/**
 * Pisahkan kejadian EMSC yang menunggu validasi dari yang sudah cocok dengan data resmi.
 * @returns {{ pending: object[], matched: Map<string, object> }} matched: kunci EMSC menjadi kejadian resmi pasangannya
 */
export function matchOfficial(emscQuakes, officialQuakes) {
    const pending = [];
    const matched = new Map();
    for (const e of emscQuakes) {
        const hit = officialQuakes.find((o) => sameEvent(e, o));
        if (hit) matched.set(e.key, hit);
        else pending.push(e);
    }

    return { pending, matched };
}

/** fetch yang dibatalkan sendiri setelah `ms` milidetik; sinyal luar tetap dihormati. */
export async function fetchWithTimeout(url, ms, outer) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), ms);
    outer?.addEventListener('abort', () => ctl.abort(), { once: true });
    try {
        const res = await fetch(url, { signal: ctl.signal, cache: 'no-store' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        return await res.json();
    } finally {
        clearTimeout(timer);
    }
}

/** Cadangan USGS: gempa satu jam terakhir di Indonesia. Melempar Error bila jaringan gagal. */
export async function fetchUsgsFallback(signal) {
    return normalizeUsgs(await fetchWithTimeout(USGS_HOUR_URL, 8000, signal));
}

/** Jeda tunggu sambung ulang WebSocket: 2, 4, 8 ... maksimum 60 detik. */
export function backoffMs(attempt) {
    return Math.min(60_000, 2000 * 2 ** Math.max(0, attempt));
}
