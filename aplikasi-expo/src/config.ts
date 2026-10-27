import AsyncStorage from '@react-native-async-storage/async-storage';
import Constants from 'expo-constants';

const KEY = 'igi:server-url';
const DEFAULT_URL = (process.env.EXPO_PUBLIC_IGI_URL ?? (Constants.expoConfig?.extra?.igiUrl as string | undefined) ?? '').replace(/\/+$/, '');

// Alamat server IGI (Laravel). Bawaan dari EXPO_PUBLIC_IGI_URL (eas.json); pengguna bisa menggantinya di aplikasi
// (disimpan di perangkat), sehingga APK tetap berguna saat alamat terowongan berubah. Hanya alamat publik, bukan rahasia.
export let SERVER_URL = DEFAULT_URL;

export function validServerUrl(url: string): boolean {
    return /^https:\/\/[a-z0-9.-]+\.[a-z]{2,}(:\d+)?$/i.test(url) || (__DEV__ && /^http:\/\/[a-z0-9.-]+(:\d+)?$/i.test(url));
}
export const serverOk = () => validServerUrl(SERVER_URL);

export async function loadServerUrl(): Promise<string> {
    try {
        const saved = await AsyncStorage.getItem(KEY);
        if (saved && validServerUrl(saved)) SERVER_URL = saved;
    } catch { /* pakai bawaan */ }
    return SERVER_URL;
}

export async function saveServerUrl(raw: string): Promise<{ ok: boolean; message?: string }> {
    const url = raw.trim().replace(/\/+$/, '');
    if (!validServerUrl(url)) return { ok: false, message: 'Alamat harus berupa https, contoh: https://igi.contoh.id' };
    try {
        const res = await fetch(`${url}/api/v1/ping`, { headers: { Accept: 'application/json' } });
        const body = await res.json().catch(() => null);
        if (!res.ok || body?.status !== 'ok') return { ok: false, message: 'Alamat itu tidak menjawab seperti server IGI.' };
    } catch {
        return { ok: false, message: 'Server tidak dapat dihubungi. Periksa alamat dan koneksi.' };
    }
    SERVER_URL = url;
    try { await AsyncStorage.setItem(KEY, url); } catch { /* hanya berlaku sementara */ }
    return { ok: true };
}

// Palet mode gelap pemantau gempa (lihat DESIGN.md): biru-hitam, permukaan biru laut, satu aksen teal.
export const COLORS = {
    bg: '#0B1622',
    surface: '#12263A',
    raised: '#183049',
    edge: '#24405C',
    ink: '#E6EEF5',
    muted: '#8FA6BA',
    accent: '#2FD1C3',
    accentSoft: '#12384A',
    danger: '#FF6B4A',
    // Nama lama dipertahankan agar layar yang belum ditulis ulang ikut berganti rupa.
    paper: '#0B1622',
    paperDeep: '#12263A',
    navy: '#E6EEF5',
    blue: '#2FD1C3',
    teal: '#2FD1C3',
    white: '#12263A',
    warn: '#3A2A1E',
    warnEdge: '#FF9F43',
};

export function magnitudeColor(m: number | null | undefined): string {
    // Skala magnitudo versi terang untuk latar gelap (data, bukan hiasan).
    if (m == null) return '#8FA6BA';
    if (m >= 6.5) return '#FF4D7A';
    if (m >= 5.5) return '#FF6B4A';
    if (m >= 4.5) return '#FF9F43';
    return '#F2C94C';
}
