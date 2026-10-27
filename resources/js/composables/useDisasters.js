import { ref } from 'vue';

export const HOUR_OPTIONS = [
    { value: 24, label: '24 jam' },
    { value: 168, label: '7 hari' },
    { value: 720, label: '30 hari' },
];

export const MAGNITUDE_OPTIONS = [
    { value: 0, label: 'Semua' },
    { value: 4.5, label: 'M 4,5+' },
    { value: 5, label: 'M 5+' },
    { value: 6, label: 'M 6+' },
];

async function getJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.json();
}

export function useDisasters(initialEvents, initialCamps) {
    const events = ref(initialEvents.features);
    const camps = ref(initialCamps.features);

    const hours = ref(168);
    const minMagnitude = ref(0);
    const loading = ref(false);
    const error = ref(null);

    const selectedId = ref(null);
    const detail = ref(null);
    const detailLoading = ref(false);

    // Hanya hasil permintaan terakhir yang dipakai, supaya filter yang diganti cepat tidak saling menimpa.
    let listRequest = 0;

    async function reload() {
        const request = ++listRequest;
        loading.value = true;
        error.value = null;

        const query = new URLSearchParams({ hours: hours.value, limit: 300 });
        if (minMagnitude.value > 0) query.set('min_magnitude', minMagnitude.value);

        try {
            const data = await getJson(`/api/v1/disasters?${query}`);
            if (request === listRequest) events.value = data.features;
        } catch {
            if (request === listRequest) error.value = 'Data gempa tidak bisa dimuat. Periksa koneksi lalu coba lagi.';
        } finally {
            if (request === listRequest) loading.value = false;
        }
    }

    function setHours(value) {
        hours.value = value;
        return reload();
    }

    function setMinMagnitude(value) {
        minMagnitude.value = value;
        return reload();
    }

    async function select(id) {
        selectedId.value = id;
        detail.value = null;

        if (!id) return;

        detailLoading.value = true;

        try {
            const data = await getJson(`/api/v1/disasters/${id}`);
            if (selectedId.value === id) detail.value = data;
        } catch {
            if (selectedId.value === id) detail.value = { failed: true };
        } finally {
            if (selectedId.value === id) detailLoading.value = false;
        }
    }

    function passesFilters(feature) {
        const ageMs = Date.now() - new Date(feature.properties.occurred_at).getTime();

        return ageMs <= hours.value * 3_600_000 && (feature.properties.magnitude ?? 0) >= minMagnitude.value;
    }

    /** Menambahkan gempa dari siaran langsung. Mengembalikan true kalau tampil di daftar. */
    function addLive(feature) {
        if (events.value.some((e) => e.id === feature.id) || !passesFilters(feature)) return false;

        events.value = [feature, ...events.value];

        return true;
    }

    return {
        events, camps, hours, minMagnitude, loading, error,
        selectedId, detail, detailLoading,
        reload, setHours, setMinMagnitude, select, addLive,
    };
}
