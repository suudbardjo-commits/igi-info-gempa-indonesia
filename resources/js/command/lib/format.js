// Pemformat tampilan untuk HUD. Angka memakai pemisah desimal Indonesia dan lebar tetap (tabular).

const dtf = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' });
const tf = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' });

export const fmtWib = (d) => (d ? `${dtf.format(d).replace(/\./g, ':').replace(' pukul ', ', ')} WIB` : '-');
export const fmtClockWib = (d) => (d ? `${tf.format(d).replace(/\./g, ':')} WIB` : '-');

export const fmtKm = (km, digits = 1) => (km == null || !Number.isFinite(km) ? '-' : `${km.toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits })} km`);

/** Detik menjadi mm:ss (atau h:mm:ss). Nilai negatif diperlakukan nol. */
export function fmtDuration(sec) {
    if (sec == null || !Number.isFinite(sec)) return '--:--';
    const s = Math.max(0, Math.round(sec));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    const pad = (n) => String(n).padStart(2, '0');

    return h ? `${h}:${pad(m)}:${pad(r)}` : `${pad(m)}:${pad(r)}`;
}

export function fmtAgo(date, now = Date.now()) {
    if (!date) return '-';
    const s = Math.max(0, (now - date.getTime()) / 1000);
    if (s < 90) return 'baru saja';
    if (s < 5400) return `${Math.round(s / 60)} menit lalu`;
    if (s < 129_600) return `${Math.round(s / 3600)} jam lalu`;

    return `${Math.round(s / 86_400)} hari lalu`;
}

export const fmtCoord = (lat, lng) => `${Math.abs(lat).toFixed(2)}° ${lat >= 0 ? 'LU' : 'LS'}, ${Math.abs(lng).toFixed(2)}° ${lng >= 0 ? 'BT' : 'BB'}`;
