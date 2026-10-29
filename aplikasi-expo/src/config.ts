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

/** Apakah alamat itu menjawab seperti server IGI? Batas waktu pendek supaya aplikasi tidak menggantung saat dibuka. */
async function menjawab(url: string, ms = 4000): Promise<boolean> {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), ms);
    try {
        const res = await fetch(`${url}/api/v1/ping`, { headers: { Accept: 'application/json' }, signal: ctl.signal });
        const body = await res.json().catch(() => null);
        return res.ok && body?.status === 'ok';
    } catch {
        return false;
    } finally {
        clearTimeout(timer);
    }
}

/**
 * Alamat yang dipakai saat aplikasi dibuka. Alamat tersimpan (diketik pengguna) hanya dipakai bila masih menjawab. Bila sudah
 * mati dan alamat bawaan (dari pembaruan terbaru) hidup, alamat bawaan dipakai dan yang basi dibuang, jadi alamat terowongan
 * yang berganti tidak perlu diketik ulang di tiap HP.
 */
export async function loadServerUrl(): Promise<string> {
    let saved: string | null = null;
    try { saved = await AsyncStorage.getItem(KEY); } catch { /* pakai bawaan */ }
    if (!saved || !validServerUrl(saved) || saved === DEFAULT_URL) return SERVER_URL;
    const [savedOk, defaultOk] = await Promise.all([menjawab(saved), validServerUrl(DEFAULT_URL) ? menjawab(DEFAULT_URL) : Promise.resolve(false)]);
    if (savedOk) {
        SERVER_URL = saved;
    } else if (defaultOk) {
        SERVER_URL = DEFAULT_URL;
        try { await AsyncStorage.removeItem(KEY); } catch { /* abaikan */ }
    }
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

// Palet terang bergaya aplikasi bencana BMKG (lihat DESIGN.md): latar putih keabuan, biru BMKG sebagai satu warna utama,
// oranye untuk perhatian, merah untuk bahaya.
export const COLORS = {
    bg: '#F2F5F9',
    surface: '#FFFFFF',
    raised: '#E8EEF5',
    edge: '#D5DEE8',
    ink: '#1B2733',
    muted: '#566677',
    accent: '#0B67B2',
    accentSoft: '#E1EFFB',
    header: '#0B5CA5',
    danger: '#C62828',
    orange: '#E65100',
    // Nama lama dipertahankan agar layar yang belum ditulis ulang ikut berganti rupa.
    paper: '#F2F5F9',
    paperDeep: '#E8EEF5',
    navy: '#1B2733',
    blue: '#0B67B2',
    teal: '#0B67B2',
    white: '#FFFFFF',
    warn: '#FFF3E0',
    warnEdge: '#E65100',
};

// Skala magnitudo untuk latar terang (data, bukan hiasan); cukup gelap agar angka besar terbaca (>= 3:1).
export function magnitudeColor(m: number | null | undefined): string {
    if (m == null) return '#566677';
    if (m >= 6.5) return '#B71C1C';
    if (m >= 5.5) return '#D32F2F';
    if (m >= 4.5) return '#E65100';
    if (m >= 4) return '#B7791F';
    return '#2E7D32';
}
