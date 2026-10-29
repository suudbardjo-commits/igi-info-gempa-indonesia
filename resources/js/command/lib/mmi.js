// Estimasi intensitas guncangan (MMI) di titik pengguna dari magnitudo, kedalaman, dan jarak. Heuristik atenuasi IGI:
// BUKAN peta guncangan resmi (itu Shakemap BMKG) dan tidak memodelkan tanah lunak, topografi, atau mekanisme sesar.

const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

// MMI = 3,0 + 1,5 M - 3,0 log10(R) - 0,003 R. Suku terakhir meniru peredaman anelastik di jarak jauh. Dipilih agar M5 di 50 km ~ V,
// M6 di 20 km ~ VIII, M7 di 100 km ~ VII, dan M6,4 di 420 km ~ III-IV (sesuai laporan dirasakan BMKG yang II-III di ratusan km).
const C0 = 3.0;
const ANELASTIC = 0.003;
const MIN_R_KM = 5; // jarak hiposentrum minimum agar dekat sumber tidak meledak ke tak hingga

/** Jarak hiposentrum R = sqrt(D^2 + h^2). */
export function hypocentralKm(distanceKm, depthKm) {
    return Math.sqrt(distanceKm ** 2 + (depthKm ?? 10) ** 2);
}

/** MMI kontinu (1..12) untuk magnitudo M, kedalaman h (km), dan jarak horizontal D (km). Null bila masukan tak sah. */
export function mmiValue(magnitude, depthKm, distanceKm) {
    if (![magnitude, distanceKm].every(Number.isFinite) || distanceKm < 0) return null;
    const r = Math.max(MIN_R_KM, hypocentralKm(distanceKm, Number.isFinite(depthKm) ? depthKm : 10));
    const raw = 1.5 * magnitude - 3.0 * Math.log10(r) + C0 - ANELASTIC * r;
    // Batas atas menurut magnitudo: gempa kecil tidak pernah menghasilkan guncangan merusak berat di dekat sumber.
    const cap = Math.min(12, magnitude + 2.5);

    return Math.max(1, Math.min(raw, cap));
}

const LEVELS = [
    { max: 2, label: 'Tidak terasa', body: 'Hanya tercatat alat. Hampir tidak ada yang merasakan.', action: 'Tidak perlu tindakan. Pantau informasi resmi.' },
    { max: 3, label: 'Getaran sangat ringan', body: 'Dirasakan sebagian orang yang diam, terutama di lantai atas.', action: 'Tetap tenang. Tidak perlu evakuasi.' },
    { max: 4, label: 'Getaran ringan', body: 'Benda ringan tergantung bergoyang, seperti truk lewat.', action: 'Tetap tenang dan jauhi benda yang bisa jatuh.' },
    { max: 5, label: 'Getaran sedang', body: 'Dirasakan banyak orang di dalam rumah. Jendela dan piring berbunyi.', action: 'Menjauh dari kaca dan rak tinggi. Siap berlindung bila bertambah kuat.' },
    { max: 6, label: 'Guncangan agak kuat', body: 'Dirasakan hampir semua orang. Benda bergoyang dan sebagian jatuh.', action: 'Berlindung di bawah meja kokoh, lindungi kepala, tunggu guncangan berhenti.' },
    { max: 7, label: 'Guncangan kuat', body: 'Dirasakan semua orang. Perabot bergeser, plester dinding retak ringan.', action: 'Berlindung, lalu keluar ke tempat terbuka bila aman. Periksa kerusakan sebelum masuk lagi.' },
    { max: 8, label: 'Guncangan sangat kuat', body: 'Sulit berdiri. Kerusakan ringan sampai sedang pada bangunan biasa.', action: 'Evakuasi ke tempat terbuka, jauhi bangunan retak. Waspada gempa susulan.' },
    { max: 9, label: 'Guncangan merusak', body: 'Kerusakan berarti pada bangunan; cerobong dan dinding runtuh.', action: 'Segera ke tempat terbuka, bantu yang terluka, ikuti arahan BPBD dan BNPB.' },
    { max: 12, label: 'Guncangan merusak berat', body: 'Kerusakan luas. Bangunan banyak yang runtuh.', action: 'Selamatkan diri, hubungi 112, ikuti arahan petugas.' },
];

/** Nilai MMI menjadi { value, mmi (1..12 bulat), roman, label, body, action }. Null bila nilai tak sah. */
export function describeMmi(value) {
    if (!Number.isFinite(value)) return null;
    const mmi = Math.max(1, Math.min(12, Math.round(value)));
    const level = LEVELS.find((l) => mmi < l.max) ?? LEVELS[LEVELS.length - 1];

    return { value, mmi, roman: ROMAN[mmi - 1], label: level.label, body: level.body, action: level.action };
}

/** Jalan pintas: estimasi lengkap untuk satu gempa dan posisi pengguna. */
export function estimateLocalMmi({ magnitude, depthKm, distanceKm }) {
    const est = describeMmi(mmiValue(magnitude, depthKm, distanceKm));

    return est ? { ...est, hypoKm: hypocentralKm(distanceKm, depthKm) } : null;
}
