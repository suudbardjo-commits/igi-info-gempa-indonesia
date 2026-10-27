/**
 * Membaca lokasi perangkat. Mengembalikan { latitude, longitude, accuracy } atau { error } dengan pesan yang bisa dibaca,
 * tidak pernah melempar. Lokasi hanya bonus untuk laporan; yang wajib hanya untuk serah terima (geofence).
 */
export function getPosition({ timeout = 8000, highAccuracy = true } = {}) {
    return new Promise((resolve) => {
        if (!navigator.geolocation) {
            resolve({ error: 'Peramban ini tidak mendukung lokasi.' });

            return;
        }

        navigator.geolocation.getCurrentPosition(
            (pos) =>
                resolve({
                    latitude: Number(pos.coords.latitude.toFixed(6)),
                    longitude: Number(pos.coords.longitude.toFixed(6)),
                    accuracy: Math.round(pos.coords.accuracy),
                }),
            (err) =>
                resolve({
                    error:
                        err.code === 1
                            ? 'Izin lokasi ditolak. Izinkan lokasi di pengaturan peramban.'
                            : err.code === 3
                              ? 'Lokasi terlalu lama dibaca. Coba lagi di tempat terbuka.'
                              : 'Lokasi tidak bisa dibaca.',
                }),
            { enableHighAccuracy: highAccuracy, timeout, maximumAge: 30000 },
        );
    });
}
