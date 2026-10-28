// Geometri bumi untuk telemetri jarak: jarak lingkaran besar (Haversine), arah (bearing), dan 8 arah mata angin.

const R_BUMI_KM = 6371.0088; // jari-jari rata-rata bumi (IUGG)
const rad = (d) => (d * Math.PI) / 180;
const deg = (r) => (r * 180) / Math.PI;

/** Jarak lurus di permukaan bumi (km), rumus Haversine. */
export function haversineKm(lat1, lng1, lat2, lng2) {
    const dLat = rad(lat2 - lat1);
    const dLng = rad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;

    return 2 * R_BUMI_KM * Math.asin(Math.min(1, Math.sqrt(a)));
}

/** Arah awal dari titik 1 ke titik 2, derajat 0..360 searah jarum jam dari utara. */
export function bearingDeg(lat1, lng1, lat2, lng2) {
    const dLng = rad(lng2 - lng1);
    const y = Math.sin(dLng) * Math.cos(rad(lat2));
    const x = Math.cos(rad(lat1)) * Math.sin(rad(lat2)) - Math.sin(rad(lat1)) * Math.cos(rad(lat2)) * Math.cos(dLng);

    return (deg(Math.atan2(y, x)) + 360) % 360;
}

const ARAH = ['Utara', 'Timur Laut', 'Timur', 'Tenggara', 'Selatan', 'Barat Daya', 'Barat', 'Barat Laut'];
const SINGKAT = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];

/** 8 arah mata angin (sektor 45° berpusat di tiap arah). */
export function compass8(bearing) {
    const i = Math.round((((bearing % 360) + 360) % 360) / 45) % 8;

    return { nama: ARAH[i], singkat: SINGKAT[i] };
}

/** "5.59,125.27" (lintang,bujur) menjadi {lat, lng}; null bila tidak sah. */
export function parseCoordinates(text) {
    if (typeof text !== 'string') return null;
    const parts = text.split(',').map((s) => Number.parseFloat(s.trim()));
    if (parts.length !== 2 || parts.some((n) => !Number.isFinite(n))) return null;
    const [lat, lng] = parts;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;

    return { lat, lng };
}

/** Pusat peta agar titik jatuh di tengah area pandang yang tidak tertutup panel/ticker/bottom sheet. */
export function offsetCenterPx(mapSize, insets) {
    const left = insets.left ?? 0;
    const top = insets.top ?? 0;
    const bottom = insets.bottom ?? 0;
    const right = insets.right ?? 0;
    // Titik pusat area pandang yang kosong, dalam piksel kontainer peta.
    const cx = left + (mapSize.x - left - right) / 2;
    const cy = top + (mapSize.y - top - bottom) / 2;

    // Pusat peta harus digeser sebesar selisih terhadap pusat kontainer.
    return { dx: mapSize.x / 2 - cx, dy: mapSize.y / 2 - cy };
}
