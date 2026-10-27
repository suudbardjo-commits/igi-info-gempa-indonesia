<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    point: { type: Object, default: null },
    markers: { type: Array, default: () => [] },
    /** Peringatan dengan geometri GeoJSON (MultiPolygon [bujur, lintang]) dari BMKG; digambar apa adanya. */
    alerts: { type: Array, default: () => [] },
});
const emit = defineEmits(['pick']);

const el = ref(null);
const tileError = ref(false);
let map, marker, alertLayer, markerLayer, base;

function drawAlerts() {
    alertLayer?.clearLayers();
    for (const a of props.alerts) {
        if (!a.geometry) continue;
        L.geoJSON({ type: 'Feature', geometry: a.geometry, properties: {} }, { style: { color: '#b4541a', weight: 1.5, fillColor: '#e08a3c', fillOpacity: 0.18 }, interactive: false }).addTo(alertLayer);
    }
}

function drawMarkers() {
    markerLayer?.clearLayers();
    for (const m of props.markers) {
        L.circleMarker([m.latitude, m.longitude], { radius: 6, color: '#fff', weight: 2, fillColor: '#215b84', fillOpacity: 1 }).bindTooltip(m.name, { direction: 'top' }).addTo(markerLayer);
    }
}

function place() {
    if (!map) return;
    if (marker) { marker.remove(); marker = null; }
    if (props.point) {
        marker = L.circleMarker([props.point.latitude, props.point.longitude], { radius: 8, color: '#fff', weight: 3, fillColor: '#d4472b', fillOpacity: 1 }).addTo(map);
        map.setView([props.point.latitude, props.point.longitude], Math.max(map.getZoom(), 8), { animate: false });
    }
}

onMounted(() => {
    map = L.map(el.value, { zoomControl: true, attributionControl: true, minZoom: 3, zoomSnap: 0.5 }).fitBounds([[-11.5, 94.5], [6.5, 141.5]]);
    map.attributionControl.setPrefix(false);
    base = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', { maxZoom: 16, attribution: 'Peta: Esri, HERE, Garmin, &copy; OpenStreetMap contributors' })
        .on('tileerror', () => { tileError.value = true; })
        .on('load', () => { tileError.value = false; })
        .addTo(map);
    alertLayer = L.layerGroup().addTo(map);
    markerLayer = L.layerGroup().addTo(map);
    map.on('click', (e) => emit('pick', { latitude: +e.latlng.lat.toFixed(4), longitude: +e.latlng.lng.toFixed(4) }));
    drawAlerts(); drawMarkers(); place();
    new ResizeObserver(() => map.invalidateSize()).observe(el.value);
});

onBeforeUnmount(() => map?.remove());
watch(() => props.point, place);
watch(() => props.alerts, drawAlerts, { deep: true });
watch(() => props.markers, drawMarkers, { deep: true });
</script>

<template>
    <div class="weather-map">
        <div ref="el" class="weather-map-canvas" role="application" aria-label="Peta pemilih titik cuaca. Klik peta untuk memilih titik; atau gunakan kolom pencarian." />
        <p v-if="tileError" class="weather-map-warning" role="status">Peta dasar belum termuat. Pencarian dan data cuaca tetap bisa dipakai.</p>
    </div>
</template>

<style scoped>
.weather-map{position:relative;height:100%;min-height:260px}.weather-map-canvas{position:absolute;inset:0;background:#dfe5ea}
.weather-map-warning{position:absolute;left:10px;right:10px;bottom:28px;z-index:500;background:#fff8e9;border:1px solid #e6c997;border-radius:4px;padding:8px 10px;font-size:12px}
</style>
