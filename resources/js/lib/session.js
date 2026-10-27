import { router } from '@inertiajs/vue3';
import { countUnsent, wipeLocalData } from './offlineDb';

/**
 * Keluar dari akun. Data lokal (antrean dan cache) dihapus supaya perangkat bersama tidak menyimpan data orang lain,
 * tetapi laporan yang belum terkirim akan ikut hilang, jadi pengguna diminta memastikan dulu.
 */
export async function logout() {
    const unsent = await countUnsent();

    if (unsent > 0 && !window.confirm(`Ada ${unsent} laporan yang belum terkirim ke server. Jika keluar sekarang, laporan itu akan hilang. Tetap keluar?`)) {
        return;
    }

    await wipeLocalData().catch(() => {});
    router.post('/keluar');
}
