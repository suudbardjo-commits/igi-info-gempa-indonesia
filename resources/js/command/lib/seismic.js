// Fisika gelombang seismik sederhana dan klasifikasi dampak. Model bumi seragam: bukan pengganti peringatan resmi.

/** Kecepatan rambat gelombang-P dan -S (km/detik), model kerak seragam. */
export const VP = 6.0;
export const VS = 3.5;

/**
 * Jari-jari muka gelombang di PERMUKAAN setelah t detik dari hiposentrum berkedalaman h km.
 * Muka gelombang berbentuk bola berjari-jari v*t; perpotongannya dengan permukaan: R = sqrt((v*t)^2 - h^2),
 * baru ada setelah v*t >= h (gelombang mencapai episentrum).
 */
export function surfaceRadiusKm(v, t, h) {
    return Math.sqrt(Math.max(0, (v * t) ** 2 - h ** 2));
}

/** Detik dari gempa sampai gelombang berkecepatan v tiba di titik berjarak epicentral d km (jarak hiposentral = sqrt(d^2 + h^2)). */
export function arrivalSeconds(v, epicentralKm, depthKm) {
    return Math.sqrt(epicentralKm ** 2 + depthKm ** 2) / v;
}

/** Warna marker menurut magnitudo (spesifikasi: <5 hijau/kuning, 5-6 oranye, >6 merah). */
export function magnitudeColor(m) {
    if (m == null || Number.isNaN(m)) return '#9CA3AF';
    if (m > 6) return '#EF4444';
    if (m >= 5) return '#F97316';
    if (m >= 4) return '#EAB308';

    return '#22C55E';
}

/**
 * Perkiraan kasar radius terasa (km) menurut magnitudo. Heuristik IGI untuk menyatakan kedekatan; BUKAN peta guncangan
 * resmi (itu Shakemap BMKG). M4: ~63 km, M5: ~200 km, M6: ~630 km.
 */
export function feltRadiusKm(m) {
    return 10 ** (0.5 * m - 0.2);
}

/** Badge kedekatan pengguna ke episentrum. */
export function proximityBadge(distanceKm, magnitude) {
    if (distanceKm < 100 && magnitude >= 5) return { kode: 'bahaya', label: 'Radius Bahaya' };
    if (distanceKm <= feltRadiusKm(magnitude)) return { kode: 'terasa', label: 'Radius Terasa' };

    return { kode: 'luar', label: 'Di Luar Dampak' };
}

/** Skala MMI dari teks "Dirasakan" BMKG ("III Tomohon", "II-III Manado"); null bila tidak ada. */
export function parseMmi(text) {
    if (typeof text !== 'string') return null;
    const m = text.trim().match(/^([IVX]+(?:\s*-\s*[IVX]+)?)\b/);

    return m ? m[1].replace(/\s+/g, '') : null;
}

/** "10 km" menjadi 10; null bila tidak terbaca. */
export function parseDepthKm(text) {
    const n = Number.parseFloat(String(text ?? '').replace(',', '.'));

    return Number.isFinite(n) && n >= 0 ? n : null;
}
