<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { offsetCenterPx } from '../lib/geo';
import { magnitudeColor } from '../lib/seismic';

const props = defineProps({
    /** Kejadian terpilih (episentrum yang disorot dan sumber gelombang). */
    quake: { type: Object, default: null },
    /** Semua kejadian untuk penanda sekunder (layer "Gempa terkini"). */
    quakes: { type: Array, default: () => [] },
    userPos: { type: Object, default: null },
    /** Radius muka gelombang di permukaan (km). */
    rp: { type: Number, default: 0 },
    rs: { type: Number, default: 0 },
    /** Fungsi yang mengembalikan {top, bottom, left, right} px area peta yang tertutup UI (ticker, panel, bottom sheet). */
    getInsets: { type: Function, default: () => ({}) },
});
const emit = defineEmits(['select', 'tiles-error', 'tiles-ok', 'faults-state']);

const el = ref(null);
const map = shallowRef(null);

let epicenter = null;
let waveP = null;
let waveS = null;
let userMarker = null;
let userLine = null;
let faultLayer = null;
let faultsLoaded = false;
const recentLayer = L.layerGroup();
const waveLayer = L.layerGroup();
const userLayer = L.layerGroup();
let resizeObserver;

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const FAULT_TYPES = {
    Subduction_Thrust: 'Zona subduksi (thrust)',
    Dextral: 'Geser menganan',
    Sinistral: 'Geser mengiri',
    Dextral_Transform: 'Transform menganan',
    Sinistral_Transform: 'Transform mengiri',
    'Sinistral-Reverse': 'Geser mengiri + naik',
    Reverse: 'Sesar naik',
    Normal: 'Sesar turun',
    Spreading_Ridge: 'Punggungan pemekaran',
};

/** Tooltip dibangun dengan textContent: nama berasal dari data luar, tidak disisipkan sebagai HTML. */
function tooltipEl(lines) {
    const root = document.createElement('div');
    lines.forEach(([text, strong], i) => {
        const row = document.createElement(strong ? 'strong' : 'span');
        row.textContent = text;
        row.style.display = 'block';
        if (i) row.style.color = '#9ca3af';
        root.append(row);
    });

    return root;
}

function epicenterIcon(q) {
    const c = magnitudeColor(q.mag);

    // Titik + dua cincin yang membesar bergantian (animasi radar, lihat style .cc-epi di bawah).
    return L.divIcon({ className: 'cc-epi-wrap', html: `<div class="cc-epi" style="--c:${c}"><i></i><i></i><b></b></div>`, iconSize: [0, 0] });
}

/**
 * Menggeser pusat peta agar titik jatuh di tengah area pandang yang kosong (di luar ticker atas, panel kiri di desktop,
 * dan bottom sheet bawah di ponsel). Perhitungan di ruang piksel pada zoom tujuan, lalu dikembalikan ke lat/lng.
 */
function centerFor(latlng, zoom) {
    const m = map.value;
    const off = offsetCenterPx(m.getSize(), props.getInsets());
    const p = m.project(latlng, zoom).add([off.dx, off.dy]);

    return m.unproject(p, zoom);
}

function focus(q, { zoom = 7 } = {}) {
    const m = map.value;
    if (!m || !q) return;
    m.invalidateSize(); // ukuran kontainer harus terkini sebelum menghitung kamera
    if (m.getSize().x === 0) return;
    m.stop();
    const target = centerFor([q.lat, q.lng], zoom);
    if (reduced()) m.setView(target, zoom, { animate: false });
    else m.flyTo(target, zoom, { duration: 1.1 });
}

function showUser() {
    userLayer.clearLayers();
    userMarker = userLine = null;
    if (!props.userPos) return;
    userMarker = L.marker([props.userPos.lat, props.userPos.lng], {
        icon: L.divIcon({ className: 'cc-user-wrap', html: '<div class="cc-user"></div>', iconSize: [14, 14], iconAnchor: [7, 7] }),
        keyboard: false,
    }).bindTooltip('Lokasi Anda', { direction: 'top' }).addTo(userLayer);
    if (props.quake) drawVector();
}

/** Garis vektor teknis putus-putus dari titik GPS pengguna ke episentrum. */
function drawVector() {
    if (userLine) userLayer.removeLayer(userLine);
    userLine = null;
    if (!props.userPos || !props.quake) return;
    userLine = L.polyline([[props.userPos.lat, props.userPos.lng], [props.quake.lat, props.quake.lng]], { color: '#F9FAFB', weight: 1.5, opacity: 0.85, dashArray: '2, 6', interactive: false }).addTo(userLayer);
}

function drawRecent() {
    recentLayer.clearLayers();
    for (const q of props.quakes) {
        const c = magnitudeColor(q.mag);
        L.circleMarker([q.lat, q.lng], { radius: 3 + Math.max(0, q.mag - 3) * 1.6, color: '#090D16', weight: 1, fillColor: c, fillOpacity: 0.75 })
            .bindTooltip(tooltipEl([[`M${q.mag.toFixed(1)}`, true], [q.region]]), { direction: 'top' })
            .on('click', () => emit('select', q.key))
            .addTo(recentLayer);
    }
}

function drawEpicenter() {
    const m = map.value;
    if (!m) return;
    if (epicenter) { m.removeLayer(epicenter); epicenter = null; }
    waveLayer.clearLayers();
    waveP = waveS = null;
    if (!props.quake) return;
    epicenter = L.marker([props.quake.lat, props.quake.lng], { icon: epicenterIcon(props.quake), zIndexOffset: 1000, keyboard: false, interactive: false }).addTo(m);
    // Gelombang-P: garis putus-putus cyan. Gelombang-S: cincin merah berisi. L.circle memakai meter, jadi proporsional zoom.
    waveP = L.circle([props.quake.lat, props.quake.lng], { radius: 1, color: '#38BDF8', weight: 1.5, dashArray: '6, 6', fill: false, opacity: 0, interactive: false }).addTo(waveLayer);
    waveS = L.circle([props.quake.lat, props.quake.lng], { radius: 1, color: '#EF4444', weight: 1.5, fillColor: '#EF4444', fillOpacity: 0, opacity: 0, interactive: false }).addTo(waveLayer);
    drawVector();
}

function setWave(circle, km, fillOpacity) {
    if (!circle) return;
    const visible = km >= 0.5;
    circle.setRadius(Math.max(1, km * 1000));
    circle.setStyle({ opacity: visible ? 0.9 : 0, fillOpacity: visible ? fillOpacity : 0 });
}

async function loadFaults() {
    if (faultsLoaded) return;
    faultsLoaded = true;
    emit('faults-state', 'loading');
    try {
        const res = await fetch('/data/sesar-indonesia.geojson');
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        faultLayer.clearLayers();
        // Garis putus-putus teknis amber; hover menyorot dan menampilkan nama, tipe, dan laju geser.
        const base = { color: '#F59E0B', weight: 1.2, opacity: 0.85, dashArray: '4, 4' };
        L.geoJSON(data, {
            style: () => base,
            onEachFeature: (f, layer) => {
                const p = f.properties;
                const rate = p.slip_rate_mm_yr != null ? `laju geser ${p.slip_rate_mm_yr} mm/tahun` : 'laju geser tidak tercatat';
                layer.bindTooltip(tooltipEl([[p.name, true], [FAULT_TYPES[p.slip_type] ?? p.slip_type], [rate]]), { sticky: true });
                layer.on('mouseover', () => layer.setStyle({ weight: 3, opacity: 1 }));
                layer.on('mouseout', () => layer.setStyle(base));
            },
        }).addTo(faultLayer);
        emit('faults-state', 'ok');
    } catch {
        faultsLoaded = false; // boleh dicoba lagi saat layer dinyalakan ulang
        emit('faults-state', 'error');
    }
}

onMounted(() => {
    const m = L.map(el.value, { zoomControl: false, attributionControl: false, minZoom: 3, zoomSnap: 0.25, worldCopyJump: false });
    map.value = m;
    // Tampilan awal Indonesia. setView (bukan fitBounds) supaya aman walau kontainer belum berukuran saat dipasang.
    m.setView([-2.5, 118], window.innerWidth < 768 ? 4 : 5, { animate: false });

    L.control.zoom({ position: 'topright' }).addTo(m);
    L.control.attribution({ position: 'bottomright', prefix: false }).addTo(m);

    // Peta dasar gelap. CartoDB Dark Matter kini mengembalikan ubin "API KEY REQUIRED" tanpa kunci (HTTP 200, jadi tidak
    // terdeteksi sebagai galat), maka dipakai Esri World Dark Gray Canvas (gratis, tanpa kunci) + lapisan label. Gagal
    // memuat ubin tidak mematikan data: pemberitahuan ditampilkan induknya.
    const esri = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas';
    L.tileLayer(`${esri}/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}`, {
        maxZoom: 16,
        attribution: 'Peta: Esri, HERE, Garmin, &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
    })
        .on('tileerror', () => emit('tiles-error'))
        .on('load', () => emit('tiles-ok'))
        .addTo(m);
    L.tileLayer(`${esri}/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 16, opacity: 0.8, pane: 'shadowPane' }).addTo(m);

    faultLayer = L.layerGroup();
    recentLayer.addTo(m);
    waveLayer.addTo(m);
    userLayer.addTo(m);

    // Kontrol layer (toggle): sesar aktif, gelombang P/S, gempa terkini.
    L.control.layers(null, { 'Sesar aktif': faultLayer, 'Gelombang P / S': waveLayer, 'Gempa terkini': recentLayer }, { position: 'topright', collapsed: window.innerWidth < 768 }).addTo(m);
    m.on('overlayadd', (e) => { if (e.layer === faultLayer) loadFaults(); });
    faultLayer.addTo(m);
    loadFaults();

    resizeObserver = new ResizeObserver(() => m.invalidateSize());
    resizeObserver.observe(el.value);

    drawRecent();
    drawEpicenter();
    showUser();
    if (props.quake) focus(props.quake);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    map.value?.remove();
});

watch(() => props.quake?.key, () => {
    drawEpicenter();
    if (props.quake) focus(props.quake);
});
watch(() => props.quakes, drawRecent);
watch(() => props.userPos, () => {
    showUser();
    // Dengan lokasi pengguna, tampilkan episentrum dan pengguna sekaligus di area yang tidak tertutup UI.
    if (props.userPos && props.quake && map.value) {
        const i = props.getInsets();
        map.value.flyToBounds([[props.quake.lat, props.quake.lng], [props.userPos.lat, props.userPos.lng]], {
            paddingTopLeft: [(i.left ?? 0) + 40, (i.top ?? 0) + 40],
            paddingBottomRight: [(i.right ?? 0) + 40, (i.bottom ?? 0) + 40],
            maxZoom: 9,
            animate: !reduced(),
        });
    }
});
watch(() => props.rp, (v) => setWave(waveP, v, 0));
watch(() => props.rs, (v) => setWave(waveS, v, 0.12));

defineExpose({ focus, recenter: () => focus(props.quake), map });
</script>

<template>
    <div ref="el" class="cc-map" role="application" aria-label="Peta episentrum gempa. Daftar kejadian tersedia di panel."></div>
</template>

<style>
.cc-map { position: absolute; inset: 0; background: #090d16; font-family: 'JetBrains Mono Variable', ui-monospace, monospace; }
.cc-map .leaflet-container { background: #090d16; font: inherit; }

/* Kontrol Leaflet bergaya instrumen: sudut tegas, garis 1px, tanpa bayangan. */
.cc-map .leaflet-top { top: 32px; }
.cc-map .leaflet-bar { border: 1px solid #1f2937; border-radius: 2px; box-shadow: none; }
.cc-map .leaflet-bar a { background: #111827; color: #e5e7eb; border-bottom: 1px solid #1f2937; border-radius: 0; width: 32px; height: 32px; line-height: 32px; }
.cc-map .leaflet-bar a:hover { background: #1f2937; color: #fff; }
.cc-map .leaflet-control-layers { background: #111827; color: #e5e7eb; border: 1px solid #1f2937; border-radius: 2px; box-shadow: none; font-size: 12px; }
.cc-map .leaflet-control-layers-toggle { width: 32px; height: 32px; }
.cc-map .leaflet-control-layers-expanded { padding: 8px 10px; }
.cc-map .leaflet-control-layers label { display: flex; align-items: center; gap: 6px; min-height: 28px; }
.cc-map .leaflet-control-attribution { background: #090d16cc; color: #9ca3af; font-size: 10px; border-top-left-radius: 2px; }
.cc-map .leaflet-control-attribution a { color: #9ca3af; }
.cc-map .leaflet-tooltip { background: #111827; color: #e5e7eb; border: 1px solid #1f2937; border-radius: 2px; box-shadow: none; font: 11px/1.5 'JetBrains Mono Variable', ui-monospace, monospace; }
.cc-map .leaflet-tooltip-top::before { border-top-color: #1f2937; }

/* Ponsel: kontrol bawah (atribusi) naik mengikuti bottom sheet lewat transform (tanpa reflow). */
@media (max-width: 767px) {
    .cc-map .leaflet-bottom { transform: translateY(calc(var(--sheet-height, 0px) * -1)); }
}

/* Penanda episentrum: titik + dua cincin radar berdenyut bergantian. */
.cc-epi { position: relative; width: 0; height: 0; }
.cc-epi b { position: absolute; left: -7px; top: -7px; width: 14px; height: 14px; border-radius: 50%; background: var(--c); border: 2px solid #090d16; box-sizing: border-box; }
.cc-epi i { position: absolute; left: -7px; top: -7px; width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--c); box-sizing: border-box; opacity: 0; animation: cc-pulse 2.4s ease-out infinite; }
.cc-epi i:nth-child(2) { animation-delay: 1.2s; }
@keyframes cc-pulse { 0% { transform: scale(1); opacity: .9; } 100% { transform: scale(5); opacity: 0; } }
.cc-user { width: 14px; height: 14px; background: #f9fafb; border: 2px solid #090d16; transform: rotate(45deg); box-sizing: border-box; }
@media (prefers-reduced-motion: reduce) { .cc-epi i { animation: none; opacity: .35; transform: scale(2.4); } }
</style>
