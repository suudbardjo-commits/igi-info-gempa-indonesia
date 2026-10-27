// Warna skala kekuatan; nilainya sama dengan token --color-quake-* di app.css.
const SCALE = [
    { max: 4.5, color: '#e9b949' },
    { max: 5.5, color: '#f08a24' },
    { max: 6.5, color: '#e5412d' },
    { max: Infinity, color: '#a3123a' },
];

export function magnitudeColor(mag) {
    const m = mag ?? 0;

    return SCALE.find((s) => m < s.max).color;
}

// Varian gelap untuk angka di atas latar terang. Warna di atas dipakai untuk isian peta dan latar gelap; sebagai teks di
// latar putih, kuning dan oranye itu hanya berkontras 1,6 sampai 2,3 (WCAG meminta minimal 3 untuk teks besar).
const INK = [
    { max: 4.5, color: '#8a6400' },
    { max: 5.5, color: '#a64a08' },
    { max: 6.5, color: '#b52a1a' },
    { max: Infinity, color: '#a3123a' },
];

export function magnitudeInk(mag) {
    const m = mag ?? 0;

    return INK.find((s) => m < s.max).color;
}

export function formatMagnitude(mag) {
    return mag == null ? '–' : Number(mag).toFixed(1).replace('.', ',');
}

/** Ukuran penanda di peta (piksel), membesar dengan magnitudo. */
export function markerRadius(mag) {
    return Math.max(5, 4 + ((mag ?? 3) - 3) * 3.4);
}

/** Potensi tsunami hanya dianggap ada kalau teksnya menyebut tsunami tanpa kata "tidak". */
export function hasTsunamiPotential(text) {
    return !!text && /tsunami/i.test(text) && !/tidak/i.test(text);
}

export const SOURCE_LABEL = { bmkg: 'BMKG', usgs: 'USGS', manual: 'Input petugas' };

const zone = 'Asia/Jakarta';

export function formatWib(iso) {
    return (
        new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZone: zone,
        })
            .format(new Date(iso))
            .replace('.', ':') + ' WIB'
    );
}

export function formatAgo(iso, now = Date.now()) {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const rtf = new Intl.RelativeTimeFormat('id', { numeric: 'auto' });
    const abs = Math.abs(seconds);

    if (abs < 60) return 'baru saja';
    if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour');

    return rtf.format(Math.round(seconds / 86400), 'day');
}
