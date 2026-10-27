<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatMagnitude, magnitudeColor, markerRadius } from '../lib/quake';

const props = defineProps({
    events: { type: Array, required: true },
    camps: { type: Array, required: true },
    selectedId: { type: String, default: null },
    pulses: { type: Array, default: () => [] },
    baseMap: { type: String, default: 'gray' },
    showRings: { type: Boolean, default: true },
    /** Lebar panel yang menutupi peta di kiri (piksel), supaya fokus peta tidak tertutup. */
    inset: { type: Number, default: 0 },
    /** Tinggi lembar bawah di ponsel yang menutupi peta (piksel). */
    insetBottom: { type: Number, default: 0 },
});

const emit = defineEmits(['select', 'clear', 'camp-select', 'tile-error', 'tiles-ready']);

const container = ref(null);
const INDONESIA = [[-11.5, 94.5], [6.5, 141.5]];

let map;
let eventLayer;
let campLayer;
let pulseLayer; let baseLayer; let labelLayer; let resizeObserver;
const shapes = new Map(); // id => { ring, dot }
let viewBounds = INDONESIA; const shownPulses = new Set();

function latLng(feature) {
    const [lng, lat] = feature.geometry.coordinates;

    return [lat, lng];
}

/**
 * Isi tooltip dibuat sebagai elemen DOM dengan textContent. Teks lokasi datang dari API luar,
 * jadi tidak boleh disisipkan sebagai HTML.
 */
function tooltipContent(strong, rest) {
    const root = document.createElement('span');
    const bold = document.createElement('strong');
    bold.textContent = strong;
    root.append(bold, ` ${rest}`);

    return root;
}

function drawEvents() {
    eventLayer.clearLayers();
    shapes.clear();

    // Gempa besar digambar lebih dulu supaya yang kecil tidak tertutup.
    const sorted = [...props.events].sort((a, b) => (b.properties.magnitude ?? 0) - (a.properties.magnitude ?? 0));

    const rings = sorted.map((f) => {
        const color = magnitudeColor(f.properties.magnitude);
        const ring = L.circle(latLng(f), {
            radius: (f.properties.impact_radius_km ?? 25) * 1000,
            color,
            weight: 1.5,
            fillColor: color,
            fillOpacity: 0.1,
            interactive: false,
        });
        if (props.showRings) ring.addTo(eventLayer);

        return [f, ring];
    });

    for (const [f, ring] of rings) {
        const color = magnitudeColor(f.properties.magnitude);
        const dot = L.circleMarker(latLng(f), {
            radius: markerRadius(f.properties.magnitude),
            color: '#ffffff',
            weight: 2,
            fillColor: color,
            fillOpacity: 0.95, bubblingMouseEvents: false,
        });

        dot.bindTooltip(tooltipContent(`M ${formatMagnitude(f.properties.magnitude)}`, f.properties.location), {
            className: 'quake-tip',
            direction: 'top',
            offset: [0, -6],
        });
        dot.on('click', () => emit('select', f.id));
        dot.addTo(eventLayer);
        const element = dot.getElement();
        element?.setAttribute('tabindex', '0');
        element?.setAttribute('role', 'button');
        element?.setAttribute('aria-label', `Gempa M ${formatMagnitude(f.properties.magnitude)} di ${f.properties.location}`);
        element?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); emit('select', f.id); }
        });

        shapes.set(f.id, { ring, dot });
    }

    applySelection(false);
}

function drawCamps() {
    campLayer.clearLayers();

    for (const f of props.camps) {
        const p = f.properties;
        const marker = L.marker(latLng(f), {
            icon: L.divIcon({ className: '', html: '<span class="camp-pin"></span>', iconSize: [12, 12] }),
            title: p.name,
            keyboard: true,
        });

        marker.bindTooltip(tooltipContent(p.name, `terisi ${p.current_occupants} dari ${p.capacity_people} orang`), {
            className: 'quake-tip',
            direction: 'top',
            offset: [0, -6],
        });
        marker.on('click', () => emit('camp-select', f.id));
        marker.addTo(campLayer);
    }
}

function applySelection(fly = true) {
    for (const [id, { ring, dot }] of shapes) {
        const active = id === props.selectedId;
        ring.setStyle({ weight: active ? 2.5 : 1.5, fillOpacity: active ? 0.12 : 0.08 });
        dot.setStyle({ color: active ? '#14202b' : '#ffffff', weight: active ? 3 : 2 });
        if (active) dot.bringToFront();
    }

    const current = shapes.get(props.selectedId);

    if (fly && current) { viewBounds = current.ring.getBounds();
        map.flyToBounds(current.ring.getBounds(), {
            paddingTopLeft: [props.inset + 24, 48],
            paddingBottomRight: [24, props.insetBottom + 48],
            maxZoom: 9,
            duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 0.9,
        });
    }
}

function showPulse(pulse) {
    const icon = L.divIcon({
        className: '',
        html: `<span class="pulse" style="--c:${pulse.color}"></span>`,
        iconSize: [0, 0],
    });
    const marker = L.marker([pulse.lat, pulse.lng], { icon, interactive: false, keyboard: false }).addTo(pulseLayer);

    setTimeout(() => pulseLayer.removeLayer(marker), 8000);
}

onMounted(() => {
    // Di ponsel lembar bawah menutupi sisi bawah peta, jadi atribusi dipindah ke atas.
    map = L.map(container.value, { zoomControl: false, attributionControl: false, minZoom: 2, zoomSnap: 0.5 });
    L.control.zoom({ position: 'topright' }).addTo(map);
    L.control.attribution({ position: 'topleft', prefix: false }).addTo(map);
    L.control.scale({position:'bottomright', imperial:false}).addTo(map);

    // Peta dasar abu-abu netral dari Esri supaya warna data yang menonjol. Tidak butuh kunci API,
    // tetapi untuk produksi sebaiknya memakai penyedia tile yang lisensinya sesuai.
    const esri = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas';

    baseLayer = L.tileLayer(`${esri}/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}`, {
        maxZoom: 16,
        attribution: 'Peta: Esri, HERE, Garmin, &copy; OpenStreetMap contributors',
    }).addTo(map);

    // Nama tempat di lapisan terpisah di bawah lingkaran dampak, supaya data tetap terbaca.
    map.createPane('labels').style.zIndex = 250;
    map.getPane('labels').style.pointerEvents = 'none';

    labelLayer = L.tileLayer(`${esri}/World_Light_Gray_Reference/MapServer/tile/{z}/{y}/{x}`, {
        maxZoom: 16,
        pane: 'labels',
    }).addTo(map);

    campLayer = L.layerGroup().addTo(map);
    eventLayer = L.layerGroup().addTo(map);
    pulseLayer = L.layerGroup().addTo(map);

    map.fitBounds(INDONESIA, { paddingTopLeft: [props.inset, 0], paddingBottomRight: [0, props.insetBottom] });
    map.on('click', () => emit('clear'));

    baseLayer.on('tileerror', () => emit('tile-error'));
    baseLayer.on('tileload', () => emit('tiles-ready'));
    resizeObserver = new ResizeObserver(() => map.invalidateSize());
    resizeObserver.observe(container.value);
    drawCamps();
    drawEvents();
});

onBeforeUnmount(() => { resizeObserver?.disconnect(); map?.remove(); });

watch(() => props.events, drawEvents);
watch(() => props.camps, drawCamps);
watch(() => props.selectedId, () => applySelection(true));

watch(
    () => props.pulses,
    (list) => {
        for (const pulse of list) {
            if (shownPulses.has(pulse.key)) continue;
            shownPulses.add(pulse.key);
            showPulse(pulse);
        }
    },
    { deep: true },
);

watch(() => props.baseMap, (value) => {
    if (!map) return;
    map.removeLayer(baseLayer);
    if (map.hasLayer(labelLayer)) map.removeLayer(labelLayer);
    baseLayer = value === 'streets'
        ? L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'})
        : L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', {maxZoom: 16, attribution: 'Peta: Esri, HERE, Garmin, &copy; OpenStreetMap contributors'});
    baseLayer.on('tileerror', () => emit('tile-error'));
    baseLayer.on('tileload', () => emit('tiles-ready'));
    baseLayer.addTo(map);
    if (value === 'gray') labelLayer.addTo(map);
});
watch(() => props.insetBottom, () => {
    if (!map) return;
    if (props.selectedId) applySelection(true);
    else map.fitBounds(viewBounds, {paddingTopLeft:[props.inset + 12, 105], paddingBottomRight:[12,props.insetBottom + 20], animate:false});
});
defineExpose({
    retryTiles() { baseLayer?.redraw(); labelLayer?.redraw(); },
    fitRegion(bounds) { viewBounds = bounds; map?.fitBounds(bounds, {paddingTopLeft:[props.inset + 20, 100], paddingBottomRight:[20,props.insetBottom + 35], maxZoom:8}); },
    focusPoint(lat, lng) {
        viewBounds = [[lat - .12,lng - .12],[lat + .12,lng + .12]]; map.flyToBounds(viewBounds, { paddingTopLeft:[props.inset + 20,90], paddingBottomRight:[20,props.insetBottom + 35], maxZoom:10, duration: .6 });
    },
});
</script>

<template>
    <div ref="container" class="absolute inset-0" role="application" aria-label="Peta gempa Indonesia" />
</template>
