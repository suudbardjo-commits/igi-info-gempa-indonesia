import Dexie from 'dexie';

/**
 * Penyimpanan lokal di perangkat lapangan.
 *  - outbox: operasi yang dicatat dan menunggu dikirim (atau sudah terkirim, untuk riwayat).
 *  - cache: data dari server untuk dipakai offline (posko, daftar barang, kiriman masuk).
 */
export const db = new Dexie('lapangan-v1');

db.version(1).stores({
    outbox: 'id, status, recorded_at, created_at',
    cache: 'key',
});

/** Hapus semua data lokal. Dipanggil saat keluar supaya perangkat bersama tidak menyimpan data orang lain. */
export async function wipeLocalData() {
    await db.delete();
    // Inertia berpindah halaman tanpa memuat ulang dokumen, jadi objek `db` ini terbawa ke login berikutnya.
    // Dibuka lagi (kosong) supaya tidak tertinggal dalam keadaan tertutup (DatabaseClosedError).
    await db.open();

    if ('caches' in window) {
        const keys = await caches.keys();
        await Promise.all(keys.map((k) => caches.delete(k)));
    }
}

/** Jumlah operasi yang belum terkirim; dibaca langsung dari IndexedDB (tanpa membuka mesin sinkronisasi). */
export async function countUnsent() {
    try {
        return await db.outbox.where('status').anyOf('pending', 'sending').count();
    } catch {
        return 0;
    }
}
