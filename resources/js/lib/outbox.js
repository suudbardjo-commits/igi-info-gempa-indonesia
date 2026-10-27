import { reactive } from 'vue';
import { ApiError, api, errorMessage } from './api';
import { db } from './offlineDb';
import { uuid7 } from './uuid7';

/**
 * Antrean operasi offline.
 *
 * Alurnya: setiap laporan ditulis ke IndexedDB lebih dulu (dengan ID UUIDv7 buatan perangkat), baru dikirim. Jadi tidak
 * ada yang hilang kalau jaringan putus, tab ditutup, atau baterai habis. Pengiriman dilakukan berkelompok ke
 * /api/v1/sync/field-logs; server mengembalikan hasil per operasi, dan hanya operasi yang sudah pasti diterima (atau
 * ditolak tetap) yang berubah status. Mengirim ulang aman karena server idempoten per ID.
 *
 * Status: pending (menunggu), sending (sedang dikirim), synced (diterima), rejected (ditolak tetap, jangan dicoba lagi).
 */

export const sync = reactive({
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    syncing: false,
    unsent: 0,
    rejected: 0,
    sessionExpired: false,
    lastSyncAt: null,
    lastError: null,
});

const BATCH_SIZE = 50;
const RETENTION_MS = 7 * 24 * 3600 * 1000;

let retryAt = 0;
let failures = 0;
let started = false;

export async function refreshCounts() {
    sync.unsent = await db.outbox.where('status').anyOf('pending', 'sending').count();
    sync.rejected = await db.outbox.where('status').equals('rejected').count();
}

/** Catat operasi. Mengembalikan setelah tersimpan di perangkat; pengiriman ke server berjalan di latar. */
export async function enqueue({ type, payload, label, recordedAt = new Date() }) {
    const record = {
        id: uuid7(),
        type,
        payload,
        label,
        recorded_at: recordedAt.toISOString(),
        status: 'pending',
        message: null,
        attempts: 0,
        created_at: Date.now(),
    };

    await db.outbox.add(record);
    await refreshCounts();
    syncNow();

    return record;
}

/** Batalkan operasi yang belum terkirim, atau hapus riwayat yang sudah selesai. Yang sedang dikirim tidak bisa dibatalkan. */
export async function discard(id) {
    await db.outbox.where('id').equals(id).filter((r) => r.status !== 'sending').delete();
    await refreshCounts();
}

function failed(message) {
    failures += 1;
    // Jeda bertambah dua kali lipat tiap gagal, maksimal semenit, supaya jaringan jelek tidak dibebani.
    retryAt = Date.now() + Math.min(60_000, 2000 * 2 ** failures);
    sync.lastError = message;
}

async function setStatus(ids, status) {
    await db.outbox.where('id').anyOf(ids).modify((r) => {
        r.status = status;
        if (status === 'pending') r.attempts += 1;
    });
}

const toWire = ({ id, type, recorded_at, payload }) => ({ id, type, recorded_at, payload });

/** Terapkan hasil per operasi dari server ke antrean lokal. */
async function applyResults(chunk, results) {
    const byId = new Map(results.map((r) => [r.id, r]));

    for (const record of chunk) {
        const res = byId.get(record.id);

        await db.outbox.where('id').equals(record.id).modify((r) => {
            if (!res || res.status === 'retry') {
                r.status = 'pending';
                r.attempts += 1;
                r.message = res?.message ?? null;
            } else if (res.status === 'rejected') {
                r.status = 'rejected';
                r.message = res.message;
            } else {
                // applied, applied_stale, duplicate: server sudah punya operasi ini.
                r.status = 'synced';
                r.synced_at = Date.now();
                r.message = res.status === 'applied_stale' ? res.message : null;
                r.result = res.status;
            }
        });
    }
}

/**
 * Satu operasi yang formatnya rusak bisa membuat seluruh kiriman ditolak (422). Kirim satu per satu supaya yang lain
 * tetap lolos, dan tandai yang rusak sebagai ditolak.
 */
async function sendIndividually(chunk) {
    for (const record of chunk) {
        try {
            const res = await api('/sync/field-logs', { method: 'POST', redirectOn401: false, body: { operations: [toWire(record)] } });
            await applyResults([record], res.results);
        } catch (e) {
            if (e instanceof ApiError && e.status === 422) {
                await db.outbox.where('id').equals(record.id).modify((r) => {
                    r.status = 'rejected';
                    r.message = errorMessage(e);
                });
            } else {
                await setStatus([record.id], 'pending');
                throw e;
            }
        }
    }
}

export async function syncNow({ force = false } = {}) {
    if (sync.syncing || (!force && Date.now() < retryAt)) return;

    sync.syncing = true;

    try {
        for (;;) {
            const pending = await db.outbox.where('status').equals('pending').sortBy('recorded_at');
            if (!pending.length) break;

            const chunk = pending.slice(0, BATCH_SIZE);
            const ids = chunk.map((r) => r.id);
            await setStatus(ids, 'sending');

            try {
                const res = await api('/sync/field-logs', { method: 'POST', redirectOn401: false, body: { operations: chunk.map(toWire) } });

                sync.online = true;
                sync.sessionExpired = false;
                sync.lastError = null;
                sync.lastSyncAt = Date.now();
                failures = 0;
                retryAt = 0;

                await applyResults(chunk, res.results);
            } catch (e) {
                if (e instanceof ApiError && e.status === 422) {
                    await setStatus(ids, 'pending');
                    await sendIndividually(chunk).catch(() => failed('Gagal mengirim sebagian laporan.'));
                    continue;
                }

                await setStatus(ids, 'pending');

                if (e instanceof ApiError && (e.status === 401 || e.status === 419)) {
                    // Sesi habis: laporan tetap aman di perangkat dan akan terkirim setelah masuk lagi.
                    sync.sessionExpired = true;
                } else if (e instanceof ApiError) {
                    failed(e.message);
                } else {
                    sync.online = false;
                    failed('Tidak ada koneksi.');
                }

                break;
            }
        }
    } finally {
        sync.syncing = false;
        await refreshCounts();
    }
}

/** Menyalakan pemicu sinkronisasi otomatis. Aman dipanggil berulang. */
export async function startSyncEngine() {
    if (started) return;
    started = true;

    // Tab yang tertutup di tengah pengiriman meninggalkan status "sending": kembalikan ke antrean.
    await db.outbox.where('status').equals('sending').modify((r) => (r.status = 'pending'));
    // Riwayat yang sudah selesai dan lama dibuang supaya penyimpanan tidak menggunung.
    await db.outbox.where('status').equals('synced').filter((r) => Date.now() - (r.synced_at ?? 0) > RETENTION_MS).delete();
    await refreshCounts();

    window.addEventListener('online', () => {
        sync.online = true;
        syncNow({ force: true });
    });
    window.addEventListener('offline', () => (sync.online = false));
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && syncNow());
    setInterval(() => sync.unsent > 0 && syncNow(), 15_000);

    syncNow({ force: true });
}

// ---- Cache data server untuk dipakai offline -------------------------------------------------
export async function cacheSet(key, value) {
    await db.cache.put({ key, value, cached_at: Date.now() });
}

export async function cacheGet(key) {
    return (await db.cache.get(key)) ?? null;
}
