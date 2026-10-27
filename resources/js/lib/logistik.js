// Tingkat urgensi memakai skala warna yang sama dengan kekuatan gempa di peta.
export const URGENCY = {
    critical: { label: 'Kritis', color: '#a3123a', weight: 4 },
    high: { label: 'Tinggi', color: '#e5412d', weight: 3 },
    medium: { label: 'Sedang', color: '#f08a24', weight: 2 },
    low: { label: 'Rendah', color: '#e9b949', weight: 1 },
};

export const DEMAND_STATUS = {
    pending: 'Menunggu',
    partial: 'Sebagian terpenuhi',
    fulfilled: 'Terpenuhi',
    cancelled: 'Dibatalkan',
};

export const DISPATCH_STATUS = {
    draft: { label: 'Draft', tone: 'muted' },
    dispatched: { label: 'Berangkat', tone: 'info' },
    in_transit: { label: 'Dalam perjalanan', tone: 'info' },
    received: { label: 'Diterima', tone: 'ok' },
    cancelled: { label: 'Dibatalkan', tone: 'bad' },
};

/** Angka dengan pemisah ribuan Indonesia; desimal hanya bila ada. */
export function formatQty(value) {
    const n = Number(value);

    return Number.isFinite(n) ? n.toLocaleString('id-ID', { maximumFractionDigits: 2 }) : '–';
}

export function formatKm(value) {
    return `${Number(value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} km`;
}

export function formatDate(iso) {
    return iso
        ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Jakarta' }).format(new Date(iso))
        : '–';
}

/** Sisa hari sampai tanggal kedaluwarsa (negatif bila sudah lewat); null bila tanpa tanggal. */
export function daysUntil(isoDate, now = Date.now()) {
    if (!isoDate) return null;

    return Math.floor((new Date(`${isoDate}T23:59:59+07:00`).getTime() - now) / 86_400_000);
}

export function expiryInfo(isoDate) {
    const days = daysUntil(isoDate);

    if (days === null) return { text: 'Tanpa tanggal', tone: 'muted' };
    if (days < 0) return { text: `Lewat ${Math.abs(days)} hari`, tone: 'bad' };
    if (days <= 7) return { text: `${days} hari lagi`, tone: 'bad' };
    if (days <= 30) return { text: `${days} hari lagi`, tone: 'warn' };

    return { text: formatDate(isoDate), tone: 'muted' };
}

export const TONE_CLASS = {
    muted: 'text-muted',
    info: 'text-tsunami',
    ok: 'text-camp',
    warn: 'text-quake-2',
    bad: 'text-quake-3',
};
