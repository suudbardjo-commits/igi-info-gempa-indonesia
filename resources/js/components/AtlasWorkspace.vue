<script setup>
import '@fontsource-variable/figtree';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BrandMark from './BrandMark.vue';
import Icon from './Icon.vue';
import MapView from './MapView.vue';
import { useDisasters, HOUR_OPTIONS, MAGNITUDE_OPTIONS } from '../composables/useDisasters';
import { useTextSize } from '../composables/useTextSize';
import { echo } from '../echo';
import { formatAgo, formatMagnitude, formatWib, hasTsunamiPotential, magnitudeInk } from '../lib/quake';

const props = defineProps({ events: Object, camps: Object, official: { type: Object, default: () => ({ items: [], sources: [] }) }, announcements: { type: Array, default: () => [] } });
const d = useDisasters(props.events, props.camps);
const page = usePage();
const { big, toggle } = useTextSize();
const mapView = ref(null);
const section = ref('events');
const query = ref('');
const region = ref('all');
const expanded = ref(false);
const layersOpen = ref(false);
const showEvents = ref(true);
const showCamps = ref(true);
const baseMap = ref('gray');
const selectedCamp = ref(null);
const now = ref(Date.now());
const live = ref('connecting');
const receivedAt = ref(Date.now());
const tileError = ref(false);
const isMobile = ref(false);
const mapHeight = ref(600);
const notice = ref('');
const newEvent = ref(null);
const customRegion = ref(null);
const REGIONS = [
    { id: 'all', name: 'Seluruh Indonesia', bounds: [[-11.5, 94.5], [6.5, 141.5]] },
    { id: 'sumatra', name: 'Sumatra', bounds: [[-6.5, 94.5], [6.5, 107.5]] },
    { id: 'java', name: 'Jawa & Bali', bounds: [[-9.7, 104.5], [-5.5, 116]] },
    { id: 'kalimantan', name: 'Kalimantan', bounds: [[-4.5, 108], [4.5, 119.5]] },
    { id: 'sulawesi', name: 'Sulawesi', bounds: [[-6.5, 118.5], [2.5, 126]] },
    { id: 'nusa', name: 'Nusa Tenggara', bounds: [[-11.5, 116], [-7, 126]] },
    { id: 'maluku', name: 'Maluku', bounds: [[-8.5, 126], [3, 134]] },
    { id: 'papua', name: 'Papua', bounds: [[-10, 130], [1, 141.5]] },
];
const regionList = computed(() => customRegion.value ? [...REGIONS, customRegion.value] : REGIONS);
const activeRegion = computed(() => regionList.value.find(r => r.id === region.value) ?? REGIONS[0]);
function inRegion(f) {
    if (region.value === 'all') return true;
    const [lng, lat] = f.geometry.coordinates;
    const [[south, west], [north, east]] = activeRegion.value.bounds;
    return lat >= south && lat <= north && lng >= west && lng <= east;
}
const placeQuery = ref('');
const placeResults = ref([]);
const placeState = ref('idle');
const placeOpen = ref(false);
let placeTimer, placeController, placeToken = 0;
function searchPlace() {
    clearTimeout(placeTimer); placeController?.abort();
    const q = placeQuery.value.trim();
    if (q.length < 2) { placeResults.value = []; placeState.value = 'idle'; return; }
    placeState.value = 'loading'; placeOpen.value = true;
    const token = ++placeToken;
    placeTimer = setTimeout(async () => {
        placeController = new AbortController();
        try {
            const r = await fetch('/api/v1/locations?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: placeController.signal });
            const body = await r.json();
            if (token !== placeToken) return;
            if (!r.ok || body.status !== 'ok') throw new Error(body?.error?.message ?? 'galat');
            placeResults.value = body.results; placeState.value = body.results.length ? 'ok' : 'empty';
        } catch (e) { if (e.name !== 'AbortError' && token === placeToken) { placeResults.value = []; placeState.value = 'error'; } }
    }, 300);
}
function choosePlace(r) {
    const pad = 1.0;
    customRegion.value = { id: 'place', name: 'Sekitar ' + r.name, bounds: [[r.latitude - pad, r.longitude - pad], [r.latitude + pad, r.longitude + pad]], place: r };
    region.value = 'place'; placeOpen.value = false; placeQuery.value = r.name; clearSelection();
    nextTick(() => mapView.value?.fitRegion(customRegion.value.bounds));
}
const searchText = computed(() => query.value.trim().toLocaleLowerCase('id-ID'));
const events = computed(() => d.events.value.filter(f => inRegion(f) && (!searchText.value || `${f.properties.location} ${f.properties.source}`.toLocaleLowerCase('id-ID').includes(searchText.value))).sort((a,b) => new Date(b.properties.occurred_at) - new Date(a.properties.occurred_at)));
const camps = computed(() => d.camps.value.filter(f => inRegion(f) && (!searchText.value || `${f.properties.name} ${f.properties.code}`.toLocaleLowerCase('id-ID').includes(searchText.value))));
const selected = computed(() => events.value.find(f => f.id === d.selectedId.value));
const mobileInset = computed(() => isMobile.value ? Math.round(mapHeight.value * (expanded.value ? .84 : .43)) : 0);
const workHref = computed(() => {
    const user = page.props.auth?.user;
    if (!user) return '/masuk';
    if (user.can?.['dispatch.create'] || user.can?.['demand.view-all']) return '/logistik';
    return user.can?.['field-report.create'] ? '/lapangan' : '/akun';
});
const exportHref = computed(() => {
    const q = new URLSearchParams({ hours: d.hours.value });
    if (d.minMagnitude.value > 0) q.set('min_magnitude', d.minMagnitude.value);
    if (searchText.value) q.set('q', query.value.trim());
    if (region.value !== 'all') { const [[south, west], [north, east]] = activeRegion.value.bounds; q.set('south', south); q.set('north', north); q.set('west', west); q.set('east', east); }
    return '/api/v1/disasters-export?' + q;
});
const statusText = computed(() => live.value === 'connected' ? 'Siaran tersambung' : live.value === 'connecting' ? 'Menyambungkan' : 'Siaran terputus');
const timeLabel = computed(() => HOUR_OPTIONS.find(o => o.value === d.hours.value)?.label);
function clearSelection() { d.select(null); selectedCamp.value = null; }
function selectEvent(id) { showEvents.value = true; notice.value = ''; section.value = 'events'; selectedCamp.value = null; d.select(id); }
function selectCamp(id) {
    const camp = camps.value.find(c => c.id === id);
    if (!camp) return;
    showCamps.value = true; d.select(null); selectedCamp.value = camp; section.value = 'camps';
    mapView.value?.focusPoint(camp.geometry.coordinates[1], camp.geometry.coordinates[0]);
}
function chooseSection(value) { section.value = value; clearSelection(); if (value === 'info' && sources.value.state === 'idle') loadSources(); }
function changeRegion() { clearSelection(); mapView.value?.fitRegion(activeRegion.value.bounds); }
function reset() { query.value = ''; region.value = 'all'; customRegion.value = null; placeQuery.value = ''; placeResults.value = []; clearSelection(); mapView.value?.fitRegion(REGIONS[0].bounds); }
async function changeHours(value) { clearSelection(); await d.setHours(Number(value)); if (!d.error.value) receivedAt.value = Date.now(); }
async function changeMagnitude(value) { clearSelection(); await d.setMinMagnitude(Number(value)); if (!d.error.value) receivedAt.value = Date.now(); }
async function refresh() { await d.reload(); if (!d.error.value) receivedAt.value = Date.now(); }
function occupancy(c) { return c.properties.capacity_people ? Math.round(c.properties.current_occupants / c.properties.capacity_people * 100) : null; }
async function shareEvent() {
    if (!selected.value) return;
    const url = new URL(`/berita/${selected.value.id}`, window.location.origin).href;
    try { await navigator.clipboard.writeText(url); notice.value = 'Tautan kejadian disalin.'; }
    catch { notice.value = 'Penyalinan tidak tersedia. Gunakan tautan “Halaman kejadian”.'; }
}
function onState({ current }) { live.value = current === 'connected' ? 'connected' : current === 'connecting' ? 'connecting' : 'offline'; }
function onLive(event) {
    const feature = { type: 'Feature', id: event.id, geometry: { type: 'Point', coordinates: [event.longitude, event.latitude] }, properties: { ...event, is_verified: true } };
    if (d.addLive(feature)) { receivedAt.value = Date.now(); newEvent.value = feature; }
}
function onResize() { isMobile.value = window.innerWidth < 768; mapHeight.value = document.querySelector('.atlas-body')?.clientHeight ?? 600; }
const alertInfo = ref({ state: 'idle', count: 0, checkedAt: null, first: null });
async function loadAlerts() {
    try {
        const r = await fetch('/api/v1/weather-alerts', { headers: { Accept: 'application/json' } });
        const b = await r.json();
        if (!r.ok || b.status !== 'ok') throw new Error();
        alertInfo.value = { state: 'ok', count: b.count, checkedAt: b.checked_at, first: b.alerts[0] ?? null, stale: b.is_stale };
    } catch { alertInfo.value = { state: 'error', count: 0, checkedAt: null, first: null }; }
}
const sources = ref({ state: 'idle', list: [] });
async function loadSources() {
    sources.value = { ...sources.value, state: 'loading' };
    try { const r = await fetch('/api/v1/sources/status', { headers: { Accept: 'application/json' } }); const b = await r.json(); sources.value = { state: 'ok', list: b.sources }; }
    catch { sources.value = { state: 'error', list: [] }; }
}
const statusLabel = { healthy: 'Normal', late: 'Terlambat', failed: 'Gagal', unknown: 'Belum diketahui' };
const online = ref(true);
const fromSnapshot = ref(null);
const SNAP = 'igi:pantauan:snapshot';
function onNet() { online.value = navigator.onLine !== false; }
function urlState() {
    const q = new URLSearchParams();
    if (region.value === 'place' && customRegion.value) { q.set('tempat', customRegion.value.place.name); q.set('plat', customRegion.value.place.latitude.toFixed(2)); q.set('plon', customRegion.value.place.longitude.toFixed(2)); }
    else if (region.value !== 'all') q.set('wilayah', region.value);
    if (d.hours.value !== 168) q.set('waktu', d.hours.value);
    if (d.minMagnitude.value > 0) q.set('mag', d.minMagnitude.value);
    if (query.value.trim()) q.set('q', query.value.trim());
    if (section.value !== 'events') q.set('tab', section.value);
    if (selected.value) q.set('kejadian', selected.value.id);
    try { history.replaceState(null, '', q.toString() ? location.pathname + '?' + q : location.pathname); } catch { /* tautan tidak diperbarui */ }
}
watch([region, () => d.hours.value, () => d.minMagnitude.value, query, section, () => d.selectedId.value], urlState);
watch(() => d.events.value, (list) => { if (fromSnapshot.value || !list.length) return; try { localStorage.setItem(SNAP, JSON.stringify({ at: Date.now(), events: list.slice(0, 300), camps: d.camps.value })); } catch { /* cache luring tidak tersedia */ } });
let clock, connection, alertClock;
watch(query, clearSelection);
onMounted(async () => {
    await nextTick(); onResize(); window.addEventListener('resize', onResize);
    window.addEventListener('online', onNet); window.addEventListener('offline', onNet); onNet();
    if (!d.events.value.length) { try { const snap = JSON.parse(localStorage.getItem(SNAP) ?? 'null'); if (snap?.events?.length) { fromSnapshot.value = snap.at; d.events.value = snap.events; if (!d.camps.value.length) d.camps.value = snap.camps ?? []; } } catch { /* tanpa cache */ } }
    const q = new URLSearchParams(location.search);
    const wl = q.get('wilayah'); if (wl && REGIONS.some(r => r.id === wl)) region.value = wl;
    const plat = parseFloat(q.get('plat')), plon = parseFloat(q.get('plon'));
    if (q.get('tempat') && Number.isFinite(plat) && Number.isFinite(plon)) choosePlace({ name: q.get('tempat').slice(0, 80), latitude: plat, longitude: plon });
    if (q.get('q')) query.value = q.get('q').slice(0, 80);
    if (['events', 'camps', 'info'].includes(q.get('tab'))) section.value = q.get('tab');
    if (section.value === 'info') loadSources();
    const hrs = Number(q.get('waktu')), mg = Number(q.get('mag'));
    if ([24, 168, 720].includes(hrs) && hrs !== d.hours.value) await d.setHours(hrs);
    if ([4.5, 5, 6].includes(mg)) await d.setMinMagnitude(mg);
    if (region.value !== 'all' && region.value !== 'place') mapView.value?.fitRegion(activeRegion.value.bounds);
    if (q.get('kejadian') && /^[0-9a-f-]{36}$/i.test(q.get('kejadian'))) selectEvent(q.get('kejadian'));
    loadAlerts(); alertClock = setInterval(loadAlerts, 180000);
    clock = setInterval(() => now.value = Date.now(), 30000);
    echo.channel('public-disasters').listen('.earthquake.detected', onLive);
    connection = echo.connector.pusher.connection; onState({current: connection.state}); connection.bind('state_change', onState);
});
onBeforeUnmount(() => { clearInterval(clock); clearInterval(alertClock); window.removeEventListener('online', onNet); window.removeEventListener('offline', onNet); window.removeEventListener('resize', onResize); echo.leave('public-disasters'); connection?.unbind('state_change', onState); });
</script>

<template>
    <div class="atlas">
        <a class="skip" href="#atlas-list">Lewati ke daftar kejadian</a>
        <header class="atlas-header">
            <Link href="/" class="identity" aria-label="IGI, Info Gempa Indonesia"><BrandMark :size="34" /><span><b>IGI</b><small>Info Gempa Indonesia</small></span></Link>
            <div class="global-search-wrap"><label class="global-search"><Icon name="search" :size="18" /><span class="sr-only">Cari kota, kabupaten, atau wilayah untuk memusatkan peta</span><input v-model="placeQuery" type="search" autocomplete="off" placeholder="Cari wilayah: kota atau kabupaten" aria-controls="place-results" @input="searchPlace" @focus="placeOpen = placeResults.length > 0 || placeState !== 'idle'" @keydown.esc="placeOpen = false" /><span class="search-hint">Wilayah</span></label>
                <div v-if="placeOpen" id="place-results" class="place-results" role="region" aria-live="polite" aria-label="Hasil pencarian wilayah">
                    <p v-if="placeState === 'loading'">Mencari…</p><p v-else-if="placeState === 'error'">Pencarian wilayah gagal. Coba lagi.</p><p v-else-if="placeState === 'empty'">Tidak ada wilayah dengan nama itu.</p>
                    <ul v-else><li v-for="r in placeResults" :key="r.id"><button type="button" @click="choosePlace(r)"><b>{{ r.name }}</b><small>{{ r.admin || 'Indonesia' }}</small></button><Link :href="'/cuaca?lat=' + r.latitude.toFixed(2) + '&lon=' + r.longitude.toFixed(2) + '&nama=' + encodeURIComponent(r.name) + (r.province ? '&prov=' + encodeURIComponent(r.province) : '')">Cuaca</Link></li></ul>
                </div></div>
            <nav aria-label="Navigasi utama"><Link href="/" aria-current="page">Pantauan</Link><Link href="/cuaca">Cuaca</Link><Link href="/berita">Berita</Link><Link href="/panduan">Panduan</Link><Link href="/tentang">Tentang</Link></nav>
            <button class="text-size" type="button" :aria-pressed="big" aria-label="Perbesar ukuran teks" @click="toggle"><Icon name="text" :size="18" /></button>
            <Link :href="workHref" class="account"><Icon name="user" :size="16" /><span>{{ page.props.auth?.user ? 'Ruang kerja' : 'Petugas' }}</span></Link>
        </header>

        <main class="atlas-body">
            <section class="map-region" aria-label="Peta pantauan Indonesia">
                <MapView ref="mapView" :events="showEvents ? events : []" :camps="showCamps ? camps : []" :selected-id="selected?.id ?? null" :inset-bottom="mobileInset" :base-map="baseMap" :show-rings="false" @select="selectEvent" @camp-select="selectCamp" @clear="clearSelection" @tile-error="tileError = true" @tiles-ready="tileError = false" />
                <div class="map-context"><span class="map-context-dot"></span><b>{{ activeRegion.name }}</b><span>{{ timeLabel }}</span></div>
                <div class="map-tools">
                    <button type="button" :aria-expanded="layersOpen" aria-controls="map-layers" @click="layersOpen = !layersOpen"><Icon name="map" :size="17" />Lapisan</button>
                    <button type="button" aria-label="Kembali ke tampilan seluruh Indonesia" @click="reset"><Icon name="pin" :size="17" /><span>Indonesia</span></button>
                </div>
                <section v-if="layersOpen" id="map-layers" class="layer-menu" aria-label="Lapisan peta" @keydown.esc="layersOpen = false">
                    <div class="layer-title"><b>Atur peta</b><button type="button" aria-label="Tutup pilihan lapisan" @click="layersOpen = false">×</button></div>
                    <label><input v-model="showEvents" type="checkbox" /> Gempa tercatat <i class="event-symbol"></i></label>
                    <label><input v-model="showCamps" type="checkbox" /> Posko pengungsian <i class="camp-symbol"></i></label>
                    <fieldset><legend>Peta dasar</legend><label><input v-model="baseMap" value="gray" type="radio" /> Abu-abu</label><label><input v-model="baseMap" value="streets" type="radio" /> Jalan & tempat</label></fieldset>
                </section>
                <div v-if="alertInfo.state === 'ok' && alertInfo.count" class="alert-strip"><Icon name="warning" :size="16" /><span><b>{{ alertInfo.count }} peringatan dini cuaca BMKG aktif</b> · {{ alertInfo.first.area }}</span><Link href="/cuaca">Lihat</Link></div>
                <div v-else-if="alertInfo.state === 'error'" class="alert-strip quiet-strip" role="status"><span>Peringatan dini cuaca belum dapat dicek. Ini bukan berarti tidak ada peringatan.</span></div>
                <div v-if="tileError" class="map-warning" role="status">Peta dasar belum termuat. Daftar kejadian tetap tersedia. <button type="button" @click="mapView?.retryTiles()">Coba lagi</button></div>
                <div v-if="newEvent" class="new-event" role="status"><Icon name="pulse" :size="18" /><span>Gempa baru · M {{ formatMagnitude(newEvent.properties.magnitude) }}</span><button type="button" @click="query = ''; region = 'all'; selectEvent(newEvent.id); newEvent = null">Lihat</button><button type="button" aria-label="Tutup pemberitahuan" @click="newEvent = null">×</button></div>
                <div class="map-key"><b>Magnitudo</b><span><i style="background:#e9b949"></i>&lt; 4,5</span><span><i style="background:#f08a24"></i>4,5–5,4</span><span><i style="background:#e5412d"></i>5,5–6,4</span><span><i style="background:#a3123a"></i>≥ 6,5</span><span class="key-camp"><i class="camp-symbol"></i>Posko</span></div>
            </section>

            <aside id="atlas-list" class="sidebar" :class="{ expanded }" aria-label="Data pantauan" tabindex="-1">
                <button type="button" class="sheet-handle" :aria-expanded="expanded" @click="expanded = !expanded"><span></span>{{ expanded ? 'Ciutkan daftar' : 'Buka daftar' }} <b>{{ section === 'camps' ? camps.length : events.length }}</b></button>
                <div class="sidebar-heading"><div><span class="eyebrow">PANTAUAN WILAYAH</span><h1>Situasi terkini</h1></div><button class="refresh" type="button" :disabled="d.loading.value" aria-label="Perbarui data gempa" @click="refresh">↻</button></div>
                <label class="region-picker"><Icon name="pin" :size="16" /><span class="sr-only">Wilayah pantauan</span><select v-model="region" @change="changeRegion"><option v-for="r in regionList" :key="r.id" :value="r.id">{{ r.name }}</option></select></label>
                <p v-if="region === 'place'" class="region-note">Kotak perkiraan ±1° di sekitar tempat yang dicari; bukan batas administrasi.</p>
                <label v-if="section !== 'info' && !selected && !selectedCamp" class="list-search"><span class="sr-only">Saring daftar menurut lokasi gempa, sumber, atau nama posko</span><input v-model="query" type="search" placeholder="Saring daftar: lokasi, sumber, nama posko" /></label>
                <div class="panel-tabs" role="group" aria-label="Jenis informasi"><button type="button" :aria-pressed="section === 'events'" @click="chooseSection('events')">Kejadian <span>{{ events.length }}</span></button><button type="button" :aria-pressed="section === 'camps'" @click="chooseSection('camps')">Posko <span>{{ camps.length }}</span></button><button type="button" :aria-pressed="section === 'info'" @click="chooseSection('info')">Informasi</button></div>

                <div class="panel-scroll">
                    <template v-if="section === 'events'">
                        <div v-if="d.error.value" class="data-error" role="alert">{{ d.error.value }} <button type="button" @click="refresh">Coba lagi</button><small>Data sebelumnya tetap ditampilkan.</small></div>
                        <template v-if="selected">
                            <div class="detail-head"><button type="button" @click="clearSelection">← Daftar kejadian</button><span>{{ selected.properties.source?.toUpperCase() }}</span></div>
                            <article class="quake-detail">
                                <div class="detail-mag" :style="{ color: magnitudeInk(selected.properties.magnitude) }">M {{ formatMagnitude(selected.properties.magnitude) }}<span>Magnitudo</span></div>
                                <h2>{{ selected.properties.location }}</h2><p class="muted">{{ formatWib(selected.properties.occurred_at) }}</p>
                                <dl class="detail-data"><div><dt>Kedalaman</dt><dd>{{ selected.properties.depth_km != null ? Math.round(selected.properties.depth_km) + ' km' : 'Belum tersedia' }}</dd></div><div><dt>Koordinat</dt><dd>{{ selected.geometry.coordinates[1].toFixed(2) }}, {{ selected.geometry.coordinates[0].toFixed(2) }}</dd></div><div><dt>Sumber</dt><dd>{{ selected.properties.source?.toUpperCase() }}</dd></div></dl>
                                <p v-if="selected.properties.tsunami_potential" class="tsunami-note" :class="{ warning: hasTsunamiPotential(selected.properties.tsunami_potential) }">{{ selected.properties.tsunami_potential }}</p>
                                <p v-else class="muted detail-note">Informasi tsunami tidak tersedia pada catatan ini. Periksa pembaruan resmi BMKG.</p>
                                <div class="detail-actions"><Link :href="`/berita/${selected.id}`">Halaman kejadian ↗</Link><button type="button" @click="shareEvent">Salin tautan</button></div>
                                <p v-if="notice" role="status" class="muted">{{ notice }}</p>
                                <section class="related-camps"><h3>Posko dalam radius perkiraan</h3><p class="muted">Radius {{ selected.properties.impact_radius_km ?? '—' }} km; bukan peta guncangan resmi.</p><p v-if="d.detailLoading.value" class="muted">Memuat posko…</p><p v-else-if="d.detail.value?.failed" class="muted">Rincian posko belum termuat. <button type="button" @click="d.select(selected.id)">Coba lagi</button></p><ul v-else-if="d.detail.value?.affected_camps?.length"><li v-for="camp in d.detail.value.affected_camps" :key="camp.id">{{ camp.name }}<small>{{ Number(camp.distance_km).toFixed(1) }} km · {{ camp.current_occupants }} orang tercatat</small></li></ul><p v-else class="muted">Tidak ada posko terdaftar dalam radius ini.</p></section>
                            </article>
                        </template>
                        <template v-else>
                            <div class="filter-row"><label>Waktu<select :value="d.hours.value" @change="changeHours($event.target.value)"><option v-for="o in HOUR_OPTIONS" :value="o.value" :key="o.value">{{ o.label }}</option></select></label><label>Magnitudo<select :value="d.minMagnitude.value" @change="changeMagnitude($event.target.value)"><option v-for="o in MAGNITUDE_OPTIONS" :value="o.value" :key="o.value">{{ o.label }}</option></select></label></div>
                            <div class="list-caption"><span>{{ d.loading.value ? 'Memuat data…' : `${events.length} kejadian tercatat` }}</span><a class="csv-link" :href="exportHref" download>Unduh CSV</a></div>
                            <p v-if="!showEvents" class="layer-hidden">Penanda gempa disembunyikan di peta. <button type="button" @click="showEvents = true">Tampilkan</button></p>
                            <ul class="incident-list" :aria-busy="d.loading.value"><li v-for="event in events" :key="event.id"><button type="button" @click="selectEvent(event.id)"><span class="event-magnitude" :style="{ color: magnitudeInk(event.properties.magnitude) }"><small>M</small>{{ formatMagnitude(event.properties.magnitude) }}</span><span class="incident-copy"><b>{{ event.properties.location }}</b><span>{{ formatWib(event.properties.occurred_at) }}</span><small>{{ event.properties.source?.toUpperCase() }}<i>·</i>{{ event.properties.depth_km != null ? Math.round(event.properties.depth_km) + ' km' : 'Kedalaman belum tersedia' }}</small></span><Icon name="chevron" :size="14" /></button></li></ul>
                            <div v-if="!events.length && !d.loading.value" class="empty"><Icon name="search" :size="26" /><h2>Tidak ada kejadian yang cocok</h2><p>Coba wilayah lain, rentang waktu lebih panjang, atau hapus pencarian.</p><button type="button" @click="reset(); changeHours(168); changeMagnitude(0)">Atur ulang filter</button></div>
                            <p class="list-note">CSV berisi hingga 1.000 baris sesuai filter (kedalaman km; waktu UTC dan WIB; sumber BMKG/USGS). Menampilkan hingga 300 catatan. Tidak adanya catatan bukan penetapan bahwa suatu wilayah aman.</p>
                        </template>
                    </template>

                    <template v-else-if="section === 'camps'">
                        <div v-if="selectedCamp" class="camp-detail"><button type="button" class="back" @click="selectedCamp = null">← Daftar posko</button><span class="camp-label"><Icon name="shield" :size="18" />POSKO PENGUNGSIAN</span><h2>{{ selectedCamp.properties.name }}</h2><p class="muted">{{ selectedCamp.properties.code }}</p><dl class="detail-data"><div><dt>Pengungsi tercatat</dt><dd>{{ selectedCamp.properties.current_occupants }} orang</dd></div><div><dt>Kapasitas tercatat</dt><dd>{{ selectedCamp.properties.capacity_people }} orang</dd></div><div><dt>Status</dt><dd>{{ {open:'Aktif',full:'Penuh',closed:'Ditutup'}[selectedCamp.properties.status] ?? selectedCamp.properties.status }}</dd></div></dl><p class="detail-note muted">Konfirmasikan kondisi dan ketersediaan tempat dengan petugas setempat sebelum menuju posko.</p><Link href="/panduan">Lihat panduan kesiapsiagaan ↗</Link></div>
                        <template v-else><div class="list-caption"><span>{{ camps.length }} posko terdaftar</span><span>Wilayah terpilih</span></div><p v-if="!showCamps" class="layer-hidden">Lapisan posko disembunyikan. <button type="button" @click="showCamps = true">Tampilkan</button></p><ul class="camp-list"><li v-for="camp in camps" :key="camp.id"><button type="button" @click="selectCamp(camp.id)"><span class="camp-icon"><Icon name="shield" :size="20" /></span><span><b>{{ camp.properties.name }}</b><small>{{ camp.properties.current_occupants }} / {{ camp.properties.capacity_people }} orang</small><span class="capacity-track" aria-hidden="true"><i :style="{ width: `${Math.min(100, occupancy(camp) ?? 0)}%` }"></i></span></span><Icon name="chevron" :size="14" /></button></li></ul><div v-if="!camps.length" class="empty"><h2>Belum ada posko dalam hasil ini</h2><p>Ubah wilayah atau kata pencarian untuk melihat posko lainnya.</p><button type="button" @click="reset">Lihat seluruh Indonesia</button></div><p class="list-note">Jumlah penghuni merupakan data tercatat, bukan jaminan ketersediaan tempat saat ini.</p></template>
                    </template>

                    <section v-else class="information"><span class="eyebrow">INFORMASI & KESIAPSIAGAAN</span><h2>Pahami situasinya.<br />Siapkan langkahnya.</h2><p class="muted">Pembaruan lembaga dan panduan yang bisa Anda gunakan.</p><article v-for="a in announcements" :key="a.id" class="announcement"><span>{{ a.level }}</span><h3>{{ a.title }}</h3><p>{{ a.body }}</p></article><div class="info-links"><Link href="/panduan"><Icon name="bag" :size="21" /><span><b>Persiapan keluarga</b><small>Tas siaga dan langkah perlindungan</small></span><Icon name="chevron" :size="14" /></Link><Link href="/tentang"><Icon name="info" :size="21" /><span><b>Kenali data yang Anda lihat</b><small>Sumber, pembaruan, dan batasan</small></span><Icon name="chevron" :size="14" /></Link></div><div class="news-title"><h3>Dari lembaga</h3><Link href="/berita">Semua berita ↗</Link></div><article v-for="item in official.items.slice(0,4)" :key="item.id" class="news-item"><span>{{ item.source_label }} · {{ formatAgo(item.published_at, now) }}</span><h3><a :href="item.url" target="_blank" rel="noopener noreferrer">{{ item.title }} ↗<span class="sr-only"> (membuka situs sumber)</span></a></h3></article><p v-if="!official.items.length" class="muted">Baca pembaruan BMKG dan BNPB di <Link href="/berita">halaman berita</Link>.</p><p v-for="s in official.sources.filter(s => s.stale)" :key="s.key" class="muted">Data {{ s.label }} mungkin belum mutakhir.</p>
                    <div class="source-status"><h3>Status sumber data</h3><p v-if="sources.state === 'loading'" class="muted">Memuat…</p><p v-else-if="sources.state === 'error'" class="muted">Status sumber belum dapat dimuat.</p><ul v-else><li v-for="s in sources.list" :key="s.key"><span><b>{{ s.name }}</b><small>{{ s.last_ok ? 'Berhasil ' + formatAgo(s.last_ok, now) : 'Belum pernah berhasil' }}<template v-if="s.last_failure"> · gagal {{ formatAgo(s.last_failure.at, now) }}</template></small></span><em :class="s.status">{{ statusLabel[s.status] }}</em></li></ul></div></section>
                </div>
                <footer class="sidebar-footer"><span><i :class="live"></i>{{ statusText }}</span><span>BMKG · USGS</span><small v-if="!online" class="offline-note">Luring. Daftar memakai muatan terakhir.</small><small v-if="fromSnapshot" class="offline-note">Memakai salinan tersimpan {{ formatAgo(fromSnapshot, now) }}.</small><small>Data dimuat {{ formatAgo(receivedAt, now) }} · <Link href="/tentang">Tentang data</Link></small></footer>
            </aside>
        </main>
    </div>
</template>

<style scoped>
.atlas{--navy:#17334c;--blue:#215b84;--edge:#dce2e7;--muted:#657481;position:fixed;inset:0;display:flex;flex-direction:column;background:#eef1f3;color:#233444;font-family:'Figtree Variable',Arial,sans-serif;font-size:14px;line-height:1.5}
.atlas button,.atlas a,.atlas select{touch-action:manipulation}.atlas button{cursor:pointer}.atlas button:disabled{opacity:.5;cursor:wait}.atlas a:hover{text-decoration:underline;text-underline-offset:3px}.skip{position:absolute;left:12px;top:-80px;z-index:2000;padding:12px;background:white}.skip:focus{top:12px}
.atlas-header{height:72px;flex-shrink:0;display:flex;align-items:center;gap:22px;padding:0 24px;background:#fff;border-bottom:1px solid var(--edge);z-index:900}.identity{display:flex;gap:9px;align-items:center;flex-shrink:0;min-width:190px}.identity b{font-size:23px;line-height:1;letter-spacing:-1px}.identity-dot{color:#29709b}.identity small{display:block;font-size:10px;color:var(--muted);margin-top:3px}.global-search{display:flex;align-items:center;gap:10px;background:#f3f5f7;border:1px solid #e2e7eb;border-radius:5px;padding:0 12px;height:39px;flex:1;max-width:430px;color:var(--muted)}.global-search input{width:100%;min-width:0;background:transparent;outline-offset:3px;font-size:13px}.search-hint{font-size:10px;border-left:1px solid #d8dfe4;padding-left:10px}.atlas-header nav{display:flex;gap:22px;align-items:stretch;height:100%;margin-left:auto;white-space:nowrap}.atlas-header nav a{display:flex;align-items:center;font-size:13px;font-weight:500;color:#687784;border-bottom:3px solid transparent;padding-top:3px}.atlas-header nav a[aria-current]{color:var(--navy);border-color:#296a93;font-weight:650}.text-size{padding:8px;border:1px solid var(--edge);border-radius:4px}.account{display:flex;align-items:center;gap:7px;font-size:12px;white-space:nowrap}.atlas-body{position:relative;flex:1;min-height:0;display:grid;grid-template-columns:360px minmax(0,1fr)}
.map-region{position:relative;grid-column:2;grid-row:1;min-width:0;overflow:hidden;background:#dfe5ea}.sidebar{grid-column:1;grid-row:1;z-index:500;display:flex;flex-direction:column;min-height:0;background:#fff;border-right:1px solid #cbd4db;box-shadow:3px 0 12px #142b3b0a}.sidebar-heading{padding:22px 22px 14px;display:flex;align-items:center;justify-content:space-between}.eyebrow{font-size:9px;font-weight:650;letter-spacing:1.2px;color:#72818c}h1{font-size:24px;letter-spacing:-.7px;line-height:1.3;font-weight:650;margin-top:5px}.refresh{font-size:26px;line-height:1;width:36px;height:36px;border:1px solid var(--edge);border-radius:4px;color:#71808c}.region-picker{display:flex;align-items:center;gap:8px;margin:0 22px 18px;padding:0 10px;border:1px solid #d5dde4;border-radius:4px;color:#40576a}.region-picker select{width:100%;padding:9px 0;background:transparent;font-size:12px;min-width:0}.panel-tabs{display:flex;border-bottom:1px solid var(--edge);padding:0 16px;flex-shrink:0}.panel-tabs button{flex:1;padding:12px 4px;font-size:12px;color:var(--muted);border-bottom:2px solid transparent}.panel-tabs [aria-pressed=true]{border-color:var(--blue);font-weight:650;color:var(--blue)}.panel-tabs span{font-size:9px;display:inline-block;margin-left:4px;background:#eef2f5;border-radius:3px;padding:1px 4px}.panel-scroll{flex:1;min-height:0;overflow:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#c4ced6 transparent}.filter-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px 22px 14px;background:#fafbfc}.filter-row label{font-size:10px;color:var(--muted)}.filter-row select{display:block;width:100%;margin-top:5px;padding:7px 8px;border:1px solid #d7dfe6;background:#fff;border-radius:4px;font-size:12px;color:#334b5e}.list-caption{padding:12px 22px;display:flex;justify-content:space-between;gap:8px;font-size:10px;color:#768592;border-top:1px solid #eef1f4;border-bottom:1px solid #eef1f4}.incident-list li{border-bottom:1px solid #e8edf0}.incident-list button{display:flex;gap:14px;width:100%;align-items:center;text-align:left;padding:17px 20px}.incident-list button:hover,.camp-list button:hover{background:#f2f6fa}.event-magnitude{display:flex;align-items:baseline;gap:3px;font-size:23px;font-weight:600;min-width:48px;font-variant-numeric:tabular-nums;letter-spacing:-.6px}.event-magnitude small{font-size:9px;font-weight:500}.incident-copy{flex:1;min-width:0}.incident-copy b{display:block;font-size:12px;font-weight:600;line-height:1.5}.incident-copy>span{display:block;margin-top:5px;font-size:10px;color:#6e7c89}.incident-copy small{font-size:9px;color:#7d8b97;display:flex;gap:7px;align-items:center;margin-top:3px}.incident-copy i{font-style:normal}.incident-list button>svg{color:#a4b0b9}.list-note{padding:18px 22px;font-size:10px;line-height:1.7;color:#7b8893;background:#fafbfc}.sidebar-footer{border-top:1px solid var(--edge);padding:12px 22px;display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap;background:#fff;flex-shrink:0;font-size:10px;color:#70808c}.sidebar-footer>span:first-child{display:flex;align-items:center;gap:6px}.sidebar-footer i{width:6px;height:6px;border-radius:50%;background:#b38236}.sidebar-footer i.connected{background:#47816f}.sidebar-footer i.offline{background:#a35c4d}.sidebar-footer small{flex-basis:100%;font-size:9px}.sidebar-footer a{text-decoration:underline;text-underline-offset:3px}.sheet-handle{display:none}
.map-context{position:absolute;top:22px;left:22px;z-index:450;display:flex;align-items:center;gap:9px;padding:9px 13px;background:#ffffffed;border:1px solid #d4dde4;border-radius:4px;box-shadow:0 2px 5px #1c36440b;font-size:11px}.map-context b{font-weight:600}.map-context>span:last-child{border-left:1px solid #dce3e8;padding-left:9px;color:#7a8792;font-size:10px}.map-context-dot{width:6px;height:6px;background:#4c718b;border-radius:50%}.map-tools{position:absolute;top:22px;right:62px;z-index:550;display:flex;gap:7px}.map-tools button{display:flex;align-items:center;gap:7px;padding:8px 12px;background:white;border:1px solid #cbd5dd;border-radius:4px;font-size:11px;box-shadow:0 2px 5px #1c36440b}.layer-menu{position:absolute;top:68px;right:62px;z-index:560;background:#fff;border:1px solid var(--edge);border-radius:5px;width:250px;padding:14px 16px;box-shadow:0 5px 20px #17334c1c;font-size:12px}.layer-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.layer-title button{font-size:21px;width:28px}.layer-menu label{display:flex;align-items:center;gap:9px;min-height:35px}.layer-menu input{accent-color:var(--blue)}.layer-menu i{margin-left:auto}.layer-menu fieldset{border-top:1px solid var(--edge);margin-top:10px;padding-top:8px}.layer-menu legend{font-size:10px;color:var(--muted);padding-top:10px}.event-symbol{width:10px;height:10px;background:#f08a24;border-radius:50%}.camp-symbol{width:9px!important;height:9px!important;background:#0f766e;border-radius:1px!important}.map-key{position:absolute;bottom:28px;left:22px;z-index:450;display:flex;align-items:center;gap:14px;padding:9px 12px;border:1px solid #d7dfe5;border-radius:4px;background:#ffffffef;font-size:10px;color:#5e7181;pointer-events:none}.map-key b{font-size:9px;font-weight:600}.map-key span{display:flex;align-items:center;gap:5px}.map-key i{width:8px;height:8px;border-radius:50%}.key-camp{padding-left:11px;border-left:1px solid var(--edge)}.map-warning,.new-event{position:absolute;left:22px;right:22px;top:78px;z-index:500;background:#fff8e9;padding:12px;font-size:12px;border:1px solid #e6c997;border-radius:4px}.map-warning button,.new-event button{text-decoration:underline;font-weight:600}.new-event{top:auto;bottom:80px;display:flex;align-items:center;gap:10px;background:#fff;border-color:#ccdbe4}.new-event button:last-child{margin-left:auto;font-size:20px}.map-region :deep(.leaflet-control-zoom){margin:22px 16px 0 0;border:1px solid #cbd5dd;border-radius:4px;box-shadow:0 2px 5px #1c36440b}.map-region :deep(.leaflet-control-zoom a){color:#526a7c;font-weight:400;width:32px;height:32px;line-height:32px;font-size:21px}.map-region :deep(.leaflet-control-attribution){font-size:9px;background:#ffffffdf;padding:3px 6px;color:#71828d}.map-region :deep(.leaflet-top.leaflet-left){top:auto;bottom:0}.map-region :deep(.leaflet-control-scale){margin:0 15px 29px 0;font-size:9px}
.detail-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 22px;border-bottom:1px solid var(--edge);font-size:11px}.detail-head button{color:var(--blue)}.detail-head>span{font-size:9px;color:var(--muted)}.quake-detail,.camp-detail{padding:20px 22px}.detail-mag{font-size:36px;font-weight:600;letter-spacing:-1px;display:flex;align-items:baseline;gap:12px;margin-bottom:14px}.detail-mag span{font-size:10px;letter-spacing:0;color:var(--muted);font-weight:400}.quake-detail h2,.camp-detail h2{font-size:19px;font-weight:600;line-height:1.45;letter-spacing:-.3px;margin-bottom:9px}.muted{font-size:11px;color:var(--muted);line-height:1.7}.detail-data{border-block:1px solid var(--edge);margin:20px 0;padding:12px 0;font-size:12px}.detail-data>div{display:flex;justify-content:space-between;gap:10px;padding:5px 0}.detail-data dt{color:var(--muted)}.detail-data dd{font-weight:550;text-align:right}.detail-note{margin:14px 0}.tsunami-note{background:#edf4f8;border-left:3px solid #47728d;padding:12px;font-size:12px}.tsunami-note.warning{background:#fff2e9;border-color:#cb6133}.detail-actions{display:flex;gap:10px;margin:20px 0;font-size:11px}.detail-actions a,.detail-actions button{border:1px solid #bdccd7;border-radius:4px;padding:8px 10px;color:var(--blue)}.related-camps{padding-top:18px;border-top:1px solid var(--edge)}.related-camps h3{font-size:12px;font-weight:650;margin-bottom:6px}.related-camps ul{font-size:12px;margin-top:12px}.related-camps li{border-bottom:1px solid var(--edge);padding:10px 0}.related-camps small{display:block;font-size:10px;color:var(--muted);margin-top:5px}.related-camps .muted+ .muted{margin-top:12px}.camp-list li{border-bottom:1px solid var(--edge)}.camp-list button{display:flex;align-items:center;gap:12px;width:100%;padding:18px 22px;text-align:left}.camp-list button>span:nth-child(2){flex:1}.camp-icon{padding:8px;border-radius:4px;background:#edf5f2;color:#367668}.camp-list b{display:block;font-size:12px;font-weight:600}.camp-list small{display:block;font-size:10px;color:var(--muted);margin:5px 0 8px}.capacity-track{display:block;height:3px;background:#e2eae7;width:100%}.capacity-track i{display:block;height:3px;background:#438373}.camp-label{display:flex;align-items:center;gap:8px;color:#3c7a68;font-size:10px;margin:24px 0 12px}.camp-detail a,.back{color:var(--blue);font-size:12px}.empty{padding:30px 22px;color:var(--muted)}.empty h2{font-weight:600;color:#334d62;margin:12px 0 8px;font-size:15px}.empty p{font-size:12px;line-height:1.7}.empty button{margin-top:16px;font-size:12px;text-decoration:underline;color:var(--blue)}.data-error,.layer-hidden{padding:12px 22px;background:#fff4e7;font-size:11px;color:#876035}.data-error small{display:block;margin-top:5px}.data-error button,.layer-hidden button{text-decoration:underline}.information{padding:22px}.information h2{font-size:22px;line-height:1.35;letter-spacing:-.5px;font-weight:600;margin:10px 0}.info-links{margin:22px 0}.info-links>a{display:flex;align-items:center;gap:12px;border-block:1px solid var(--edge);padding:16px 0}.info-links>a+a{border-top:0}.info-links span{flex:1}.info-links b{display:block;font-size:12px;font-weight:600}.info-links small{display:block;font-size:10px;color:var(--muted);margin-top:4px}.news-title{display:flex;justify-content:space-between;align-items:center;font-size:11px}.news-title h3{font-weight:650}.news-title>a{font-size:10px;color:var(--blue)}.news-item{padding:16px 0;border-bottom:1px solid var(--edge)}.news-item>span{font-size:9px;color:var(--muted)}.news-item h3{font-size:13px;line-height:1.6;font-weight:550;margin-top:7px}.announcement{border-left:3px solid #467395;background:#f1f6fa;padding:12px;margin-top:18px;font-size:12px}.announcement>span{font-size:9px;text-transform:uppercase}.announcement h3{font-weight:600;margin:6px 0}.announcement p{white-space:pre-line}
@media(min-width:1600px){.atlas-body{grid-template-columns:390px minmax(0,1fr)}}
@media(max-width:1150px){.atlas-header{gap:14px;padding:0 18px}.identity{min-width:130px}.identity small{font-size:9px}.search-hint{display:none}.atlas-header nav{gap:15px}.atlas-header nav a{font-size:12px}.map-context{left:15px}.map-tools{right:58px}.map-context>span:last-child{display:none}.map-tools button:last-child span{display:none}.map-key{left:15px;gap:9px}.map-key b{display:none}}
@media(max-width:900px){.atlas-header nav a:last-child{display:none}.account span{display:none}.atlas-body{grid-template-columns:330px minmax(0,1fr)}.map-context{max-width:145px;font-size:10px}.map-context b{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.key-camp{display:none}}
@media(max-width:767px){.atlas-header{height:112px;display:grid;grid-template-columns:auto 1fr auto auto;gap:8px 12px;padding:10px 14px}.identity{min-width:0;gap:7px}.identity b{font-size:21px}.identity small{display:none}.identity :deep(svg){width:30px;height:30px}.atlas-header nav{height:38px;gap:15px;justify-content:flex-end;margin:0}.atlas-header nav a{font-size:11px}.atlas-header nav a:first-child{display:none}.account{justify-content:center}.global-search{grid-row:2;grid-column:1/-1;max-width:none;width:100%;height:39px}.global-search input{min-height:36px}.text-size{padding:4px;min-width:34px}.atlas-body{display:block}.map-region{position:absolute;inset:0}.sidebar{position:absolute;bottom:0;left:0;right:0;height:43%;border-right:0;border-top:1px solid #bdcbd6;border-radius:12px 12px 0 0;box-shadow:0 -3px 16px #17334c14;transition:height .2s;overflow:hidden}.sidebar.expanded{height:84%}.sheet-handle{display:flex;align-items:center;justify-content:center;gap:8px;min-height:35px!important;font-size:10px;color:var(--muted);flex-shrink:0}.sheet-handle>span{height:3px;width:26px;background:#b7c5cf;border-radius:5px}.sheet-handle>b{font-weight:500}.sidebar-heading{display:none}.region-picker{margin:0 16px 8px;min-height:36px}.region-picker select{min-height:36px;padding:6px 0;font-size:11px}.panel-tabs{padding:0 12px}.panel-tabs button{padding:8px 4px;min-height:36px!important}.sidebar-footer{padding:7px 16px;font-size:9px}.sidebar-footer small{display:none}.filter-row{padding:12px 16px;gap:12px}.filter-row select{min-height:36px}.list-caption{padding:10px 16px}.incident-list button{padding:14px 16px}.map-context{top:14px;left:12px;padding:8px 10px;max-width:180px}.map-tools{top:14px;right:54px}.map-tools button{padding:8px;min-height:36px!important}.map-tools button:last-child{display:none}.map-region :deep(.leaflet-control-zoom){margin:14px 12px 0 0}.map-region :deep(.leaflet-control-zoom a){width:31px;height:31px;line-height:31px}.layer-menu{top:58px;right:12px;width:240px}.layer-menu label{min-height:40px}.map-key{top:64px;bottom:auto;left:12px;padding:6px 8px;font-size:9px;gap:10px;max-width:calc(100% - 66px);flex-wrap:wrap}.map-context-dot{display:none}.map-region :deep(.leaflet-top.leaflet-left){top:48%;bottom:auto}.map-region :deep(.leaflet-control-attribution){font-size:8px;max-width:calc(100vw - 12px)}.map-region :deep(.leaflet-control-scale){display:none}.map-warning{top:100px;left:12px;right:12px;font-size:11px}.new-event{top:110px;bottom:auto;left:12px;right:12px;font-size:11px}.information{padding:18px 16px}.layer-menu .layer-title button{min-height:30px!important}.detail-head{padding:12px 16px}.quake-detail,.camp-detail{padding:18px 16px}}
.global-search-wrap{position:relative;flex:1;max-width:430px}.global-search-wrap .global-search{max-width:none}.place-results{position:absolute;top:44px;left:0;right:0;z-index:1200;background:#fff;border:1px solid var(--edge);border-radius:5px;box-shadow:0 6px 20px #17334c1f;max-height:320px;overflow:auto}.place-results p{padding:12px;font-size:12px;color:var(--muted)}.place-results li{display:flex;align-items:center;border-bottom:1px solid var(--edge)}.place-results li button{flex:1;text-align:left;padding:9px 12px;min-height:44px}.place-results li button:hover{background:#f2f6fa}.place-results small{display:block;font-size:11px;color:var(--muted)}.place-results li a{padding:0 12px;font-size:12px;color:var(--blue);min-height:44px;display:flex;align-items:center}
.region-note{margin:-10px 22px 10px;font-size:10px;color:var(--muted)}.list-search{display:block;margin:0 22px 10px}.list-search input{width:100%;border:1px solid #d5dde4;border-radius:4px;padding:8px 10px;min-height:38px;font-size:12px}
.alert-strip{position:absolute;top:68px;left:22px;z-index:455;display:flex;align-items:center;gap:9px;max-width:min(520px,calc(100% - 100px));padding:8px 12px;background:#fff2e9;border:1px solid #e0a477;border-radius:4px;font-size:12px}.alert-strip span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.alert-strip a{margin-left:auto;color:var(--blue);font-weight:600;text-decoration:underline;flex-shrink:0}.quiet-strip{background:#f6f8f9;border-color:var(--edge);color:var(--muted)}.quiet-strip span{white-space:normal}
.csv-link{color:var(--blue);text-decoration:underline;font-weight:600}.source-status{margin-top:26px;border-top:1px solid var(--edge);padding-top:14px}.source-status h3{font-size:13px;font-weight:650;margin-bottom:6px}.source-status li{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--edge);font-size:12px}.source-status small{display:block;font-size:10px;color:var(--muted)}.source-status em{font-style:normal;font-size:10px;font-weight:650;align-self:flex-start;padding:2px 6px;border-radius:3px;background:#eef2f5;white-space:nowrap}.source-status em.healthy{background:#e6f1ec;color:#2f6a57}.source-status em.late{background:#fff2dc;color:#8a5a14}.source-status em.failed{background:#fbe6e1;color:#9a3b22}.offline-note{flex-basis:100%;color:#8a5a14;font-size:10px}
@media(max-width:767px){.sidebar:not(.expanded) .list-search,.sidebar:not(.expanded) .region-note{display:none}.sidebar:not(.expanded) .filter-row{padding:8px 16px}.sidebar:not(.expanded) .filter-row label{font-size:0}.sidebar:not(.expanded) .filter-row select{margin-top:0;font-size:12px}.global-search-wrap{grid-row:2;grid-column:1/-1;max-width:none;width:100%}.global-search-wrap .global-search{height:39px}.alert-strip{top:64px;left:12px;max-width:calc(100% - 66px);font-size:11px}.region-note,.list-search{margin-inline:16px}.place-results{position:fixed;left:10px;right:10px;top:116px}}
@media(prefers-reduced-motion:reduce){.sidebar{transition:none}}
</style>
