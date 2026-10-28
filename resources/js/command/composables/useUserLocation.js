import { ref } from 'vue';

/**
 * Lokasi pengguna lewat navigator.geolocation, hanya setelah pengguna menekan tombol (opt-in). Koordinat disimpan di
 * memori halaman saja: tidak ditulis ke penyimpanan, URL, atau server.
 */
export function useUserLocation() {
    const position = ref(null); // { lat, lng, accuracy }
    const status = ref('idle'); // idle | asking | ok | denied | unavailable | timeout

    function request() {
        if (!('geolocation' in navigator)) {
            status.value = 'unavailable';

            return;
        }
        status.value = 'asking';
        navigator.geolocation.getCurrentPosition(
            (p) => {
                position.value = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy };
                status.value = 'ok';
            },
            (e) => {
                status.value = e.code === 1 ? 'denied' : e.code === 3 ? 'timeout' : 'unavailable';
            },
            { enableHighAccuracy: false, timeout: 12_000, maximumAge: 120_000 },
        );
    }

    function clear() {
        position.value = null;
        status.value = 'idle';
    }

    return { position, status, request, clear };
}
