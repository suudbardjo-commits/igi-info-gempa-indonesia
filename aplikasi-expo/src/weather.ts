import AsyncStorage from '@react-native-async-storage/async-storage';

export type SavedPlace = { name: string; admin: string; province?: string | null; latitude?: number; longitude?: number; adm4?: string };
const FAV_KEY = 'igi:favorit';
export const MAX_FAV = 10;

export const placeKey = (p: SavedPlace) => (p.adm4 ? `adm4:${p.adm4}` : `${Number(p.latitude).toFixed(2)},${Number(p.longitude).toFixed(2)}`);

export async function loadFavorites(): Promise<SavedPlace[]> {
    try {
        const list = JSON.parse((await AsyncStorage.getItem(FAV_KEY)) ?? '[]');
        return Array.isArray(list) ? list.slice(0, MAX_FAV) : [];
    } catch {
        return [];
    }
}

export async function saveFavorites(list: SavedPlace[]): Promise<void> {
    try { await AsyncStorage.setItem(FAV_KEY, JSON.stringify(list.slice(0, MAX_FAV))); } catch { /* hanya berlaku sementara */ }
}

/** Query /weather untuk sebuah tempat; koordinat dibulatkan ±1 km. */
export function weatherQuery(p: SavedPlace): string {
    return p.adm4 ? `adm4=${p.adm4}` : `lat=${Number(p.latitude).toFixed(2)}&lon=${Number(p.longitude).toFixed(2)}&name=${encodeURIComponent((p.name ?? '').slice(0, 100))}`;
}

export const TIPS = [
    { title: 'Saat gempa: Merunduk, Berlindung, Bertahan', body: 'Merunduk, berlindung di bawah meja kokoh, dan pegangan sampai guncangan berhenti. Jauhi kaca dan lemari.' },
    { title: 'Siapkan tas siaga', body: 'Air minum, makanan tahan lama, senter, obat pribadi, salinan dokumen, dan nomor penting dalam satu tas.' },
    { title: 'Dekat pantai? Naik ke tempat tinggi', body: 'Bila guncangan kuat dan lama, jangan menunggu peringatan: segera menjauh dari pantai ke tempat yang tinggi.' },
    { title: 'Pakai pesan teks, bukan telepon', body: 'Setelah gempa jaringan padat. Kirim pesan singkat dan biarkan jalur telepon untuk keadaan darurat.' },
    { title: 'Kenali jalur dan titik kumpul', body: 'Tentukan jalur keluar rumah dan titik kumpul keluarga. Latihan bersama membuat Anda lebih tenang.' },
    { title: 'Matikan listrik dan gas', body: 'Setelah guncangan berhenti dan Anda aman, matikan listrik dan gas untuk mencegah kebakaran.' },
];
