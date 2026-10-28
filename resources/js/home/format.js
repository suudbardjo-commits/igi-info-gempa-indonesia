// Pemformat dan warna untuk beranda (sama dengan aplikasi Android supaya tampilannya seragam).

const dtf = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' });

export const waktuWib = (iso) => (iso ? `${dtf.format(new Date(iso)).replace(':', '.')} WIB` : '-');

export function lalu(iso) {
    if (!iso) return '-';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 90) return 'baru saja';
    if (s < 5400) return `${Math.round(s / 60)} menit lalu`;
    if (s < 129_600) return `${Math.round(s / 3600)} jam lalu`;

    return `${Math.round(s / 86_400)} hari lalu`;
}

/** Skala magnitudo untuk latar terang (cukup gelap agar angka besar terbaca). */
export function magnitudeColor(m) {
    if (m == null) return '#566677';
    if (m >= 6.5) return '#B71C1C';
    if (m >= 5.5) return '#D32F2F';
    if (m >= 4.5) return '#E65100';
    if (m >= 4) return '#B7791F';

    return '#2E7D32';
}

export const mag1 = (m) => (m == null ? '-' : Number(m).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));
export const num = (x, unit = '', d = 0) => (x == null ? '-' : `${Number(x).toLocaleString('id-ID', { maximumFractionDigits: d })}${unit ? ` ${unit}` : ''}`);

export const WAKTU = [{ v: 24, l: '24 jam' }, { v: 168, l: '7 hari' }, { v: 720, l: '30 hari' }];
export const MAG = [{ v: 0, l: 'Semua' }, { v: 4.5, l: 'M 4,5+' }, { v: 5, l: 'M 5+' }, { v: 6, l: 'M 6+' }];

export const TIPS = [
    { title: 'Saat gempa: Merunduk, Berlindung, Bertahan', body: 'Merunduk, berlindung di bawah meja kokoh, dan pegangan sampai guncangan berhenti. Jauhi kaca dan lemari.' },
    { title: 'Siapkan tas siaga', body: 'Air minum, makanan tahan lama, senter, obat pribadi, salinan dokumen, dan nomor penting dalam satu tas.' },
    { title: 'Dekat pantai? Naik ke tempat tinggi', body: 'Bila guncangan kuat dan lama, jangan menunggu peringatan: segera menjauh dari pantai ke tempat yang tinggi.' },
    { title: 'Pakai pesan teks, bukan telepon', body: 'Setelah gempa jaringan padat. Kirim pesan singkat dan biarkan jalur telepon untuk keadaan darurat.' },
    { title: 'Kenali jalur dan titik kumpul', body: 'Tentukan jalur keluar rumah dan titik kumpul keluarga. Latihan bersama membuat Anda lebih tenang.' },
    { title: 'Matikan listrik dan gas', body: 'Setelah guncangan berhenti dan Anda aman, matikan listrik dan gas untuk mencegah kebakaran.' },
];
