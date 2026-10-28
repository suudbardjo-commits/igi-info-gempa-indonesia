<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MAP_HTML } from '../lib/mapHtml.js';

// Peta Leaflet bergaya Google Maps di dalam <iframe srcdoc>, halaman yang sama dengan aplikasi Android. Komunikasi lewat
// postMessage: data gempa/posko masuk; ketukan penanda, permintaan lokasi, dan permintaan data sesar keluar.
const props = defineProps({
    events: { type: Array, default: () => [] },
    camps: { type: Array, default: () => [] },
    selectedId: { type: String, default: null },
    /** Tinggi bagian bawah peta yang tertutup lembar (px), untuk memusatkan titik di area yang kosong. */
    bottomInset: { type: Number, default: 0 },
    recenterKey: { type: Number, default: 0 },
});
const emit = defineEmits(['select']);

const frame = ref(null);
const ready = ref(false);

function post(msg) {
    frame.value?.contentWindow?.postMessage(JSON.stringify(msg), '*');
}

async function onMessage(e) {
    if (e.source !== frame.value?.contentWindow || typeof e.data !== 'string') return;
    let m;
    try { m = JSON.parse(e.data); } catch { return; }
    if (m.type === 'ready') { ready.value = true; sendData(); sendSelect(); }
    else if (m.type === 'event') emit('select', m.id);
    else if (m.type === 'need-faults') {
        try {
            const r = await fetch('/data/sesar-indonesia.geojson');
            if (!r.ok) throw new Error('http');
            post({ type: 'faults', data: await r.json() });
        } catch {
            post({ type: 'faults-error' });
        }
    } else if (m.type === 'locate') {
        // Lokasi hanya diminta saat tombol ditekan; hanya dikirim ke peta, tidak disimpan.
        if (!('geolocation' in navigator)) { post({ type: 'me-error', message: 'Peramban ini tidak mendukung lokasi.' }); return; }
        navigator.geolocation.getCurrentPosition(
            (p) => post({ type: 'me', lat: p.coords.latitude, lng: p.coords.longitude, acc: p.coords.accuracy ?? 0, bottom: props.bottomInset }),
            (err) => post({ type: 'me-error', message: err.code === 1 ? 'Izin lokasi ditolak. Aktifkan izin lokasi di peramban lalu coba lagi.' : 'Lokasi perangkat tidak dapat ditentukan.' }),
            { enableHighAccuracy: false, timeout: 12_000, maximumAge: 120_000 },
        );
    }
}

function sendData() { if (ready.value) post({ type: 'data', events: props.events, camps: props.camps }); }
function sendSelect() {
    if (!ready.value || !props.selectedId) return;
    const f = props.events.find((x) => x.id === props.selectedId);
    post({ type: 'select', id: props.selectedId, bottom: props.bottomInset, mag: f?.properties.magnitude ?? 0 });
}

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));
watch(() => [props.events, props.camps], () => { sendData(); sendSelect(); });
watch(() => [props.selectedId, props.bottomInset, props.recenterKey], sendSelect);
</script>

<template>
    <iframe ref="frame" class="map-frame" :srcdoc="MAP_HTML" title="Peta gempa dan posko" />
</template>

<style scoped>
.map-frame { display: block; width: 100%; height: 100%; border: 0; background: #e5e3df; }
</style>
