<script setup>
import '@fontsource-variable/figtree';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BrandMark from '../components/BrandMark.vue';
import Icon from '../components/Icon.vue';
import WeatherMap from '../components/WeatherMap.vue';
import { MAX_COMPARE, MAX_FAVORITES, distanceKm, formatLocalDay, formatLocalTime, locationKey, useWeather } from '../composables/useWeather';
import { useTextSize } from '../composables/useTextSize';
import { formatAgo, formatMagnitude } from '../lib/quake';

const page = usePage();
const { big, toggle } = useTextSize();
const w = useWeather();

const queryText = ref('');
const loc = ref(null);
const tab = ref('cuaca');
const view = ref('chart');
const compare = ref([]);
const notice = ref('');
const adm4Open = ref(false);
const now = ref(Date.now());
const online = ref(true);
const nearby = ref({ state: 'idle', events: [], camps: [] });
const readIds = ref(new Set());
const onlyFavoriteAlerts = ref(false);
const national = ref({ state: 'idle', data: null, message: '' });
const expandedAlert = ref(null);
const renaming = ref(null);
const renameText = ref('');
let clock;

const READ_KEY = 'igi:cuaca:dibaca';
const key = computed(() => (loc.value ? locationKey(loc.value) : null));
const st = computed(() => (key.value ? w.states[key.value] : null));
const data = computed(() => st.value?.data ?? null);
const tz = computed(() => data.value?.timezone ?? 'Asia/Jakarta');
const tzLabel = computed(() => data.value?.tz_label ?? 'WIB');
const alertState = computed(() => st.value?.alerts ?? null);
const matchedAlerts = computed(() => (alertState.value?.ok ? alertState.value.data.alerts : []));
const alertGeometries = computed(() => matchedAlerts.value.filter((a) => a.coverage === 'polygon'));
const workHref = computed(() => (page.props.auth?.user ? '/akun' : '/masuk'));

const dash = '—';
const num = (x, unit = '', digits = 0) => (x === null || x === undefined ? dash : `${Number(x).toLocaleString('id-ID', { maximumFractionDigits: digits })}${unit ? ` ${unit}` : ''}`);
const clock24 = (iso) => formatLocalTime(iso, tz.value, tzLabel.value);

const hourly48 = computed(() => {
    if (!data.value) return [];
    const from = Date.now() - 3_600_000;
    const list = data.value.hourly.filter((h) => new Date(h.time).getTime() >= from);

    return list.slice(0, data.value.provider === 'bmkg' ? 16 : 48);
});
const hasRainProb = computed(() => hourly48.value.some((h) => h.rain_probability !== null));

const chart = computed(() => {
    const list = hourly48.value;
    const temps = list.map((h) => h.temperature).filter((t) => t !== null);
    if (list.length < 2 || !temps.length) return null;
    const lo = Math.floor(Math.min(...temps) - 1);
    const hi = Math.ceil(Math.max(...temps) + 1);
    const X = (i) => 44 + (i / (list.length - 1)) * 660;
    const Y = (t) => 18 + (1 - (t - lo) / (hi - lo || 1)) * 132;
    const segments = [];
    let cur = [];
    list.forEach((h, i) => {
        if (h.temperature === null) { if (cur.length) segments.push(cur); cur = []; } else cur.push(`${X(i).toFixed(1)},${Y(h.temperature).toFixed(1)}`);
    });
    if (cur.length) segments.push(cur);
    const maxP = Math.max(1, ...list.map((h) => (hasRainProb.value ? h.rain_probability : h.precipitation) ?? 0));
    const bars = list.map((h, i) => {
        const v = (hasRainProb.value ? h.rain_probability : h.precipitation) ?? 0;
        const height = (v / (hasRainProb.value ? 100 : maxP)) * 40;

        return { x: X(i) - 3, y: 196 - height, h: height, v };
    });
    const ticks = list.map((h, i) => ({ i, x: X(i), label: clock24(h.time).replace(` ${tzLabel.value}`, '') })).filter((t) => t.i % Math.max(1, Math.round(list.length / 8)) === 0);

    return { lo, hi, segments, bars, ticks, yTop: Y(hi), yBottom: Y(lo) };
});
const chartLabel = computed(() => {
    const t = hourly48.value.map((h) => h.temperature).filter((x) => x !== null);

    return t.length ? `Grafik suhu ${hourly48.value.length} jam ke depan di ${loc.value?.name}: terendah ${Math.min(...t)} °C, tertinggi ${Math.max(...t)} °C. Tabel setara tersedia.` : 'Grafik suhu tidak tersedia.';
});

async function select(next, from = 'search') {
    const l = { name: next.name, admin: next.admin ?? '', province: next.province ?? null, latitude: Number(next.latitude), longitude: Number(next.longitude), adm4: next.adm4 ?? null, from };
    loc.value = l;
    tab.value = 'cuaca';
    notice.value = '';
    queryText.value = '';
    w.search('');
    syncUrl();
    // Di ponsel hasilnya ada di bawah pemilih lokasi: bawa ke sana setelah memilih.
    if (window.innerWidth < 768 && ['search', 'map', 'device', 'adm4'].includes(from)) {
        const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        setTimeout(() => document.getElementById('wx-main')?.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' }), 60);
    }
    await Promise.all([w.load(l), w.loadAlerts(l)]);
    // Kode wilayah BMKG memberi nama, provinsi, dan koordinat resmi; perbarui lokasi dari jawabannya.
    const d = w.states[locationKey(l)]?.data;
    if (l.adm4 && d?.location && loc.value?.adm4 === l.adm4) {
        loc.value = { ...l, name: d.location.name ?? l.name, admin: d.location.admin ?? l.admin, province: d.location.province ?? l.province, latitude: d.location.latitude, longitude: d.location.longitude };
        w.loadAlerts(loc.value);
    }
    loadNearby();
}

function pickOnMap({ latitude, longitude }) {
    select({ name: `Titik di peta (${latitude.toFixed(2)}, ${longitude.toFixed(2)})`, admin: 'Dipilih dari peta', latitude, longitude }, 'map');
}

function useDevice() {
    notice.value = '';
    if (!navigator.geolocation) { notice.value = 'Peramban ini tidak mendukung lokasi perangkat. Cari nama tempat.'; return; }
    notice.value = 'Meminta izin lokasi…';
    navigator.geolocation.getCurrentPosition(
        (p) => {
            // Dibulatkan ±1 km, tidak disimpan, dan tidak dimasukkan ke tautan bagikan.
            select({ name: 'Lokasi perangkat (perkiraan)', admin: 'Dari izin lokasi Anda', latitude: +p.coords.latitude.toFixed(2), longitude: +p.coords.longitude.toFixed(2) }, 'device');
            notice.value = '';
        },
        (e) => { notice.value = e.code === 1 ? 'Izin lokasi ditolak. Anda tetap bisa mencari nama tempat atau memilih titik di peta.' : 'Lokasi perangkat tidak dapat ditentukan. Cari nama tempat sebagai gantinya.'; },
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 },
    );
}

// Pemilih bertingkat dari katalog kode wilayah Kemendagri yang diimpor ke server (bukan tebakan dari nama).
const LEVEL_LABELS = ['Provinsi', 'Kabupaten/kota', 'Kecamatan', 'Desa/kelurahan'];
const cascade = ref({ levels: LEVEL_LABELS.map(() => ({ options: [], value: '' })), busy: null, error: '', catalog: null });
async function loadLevel(i) {
    const parent = i === 0 ? '' : cascade.value.levels[i - 1].value;
    cascade.value.busy = i; cascade.value.error = '';
    try {
        const r = await fetch(`/api/v1/areas${parent ? `?parent=${parent}` : ''}`, { headers: { Accept: 'application/json' } });
        const b = await r.json();
        if (!r.ok || b.status !== 'ok') throw new Error(b?.error?.message ?? 'galat');
        cascade.value.levels[i].options = b.areas;
        cascade.value.catalog = b.catalog;
    } catch (e) {
        cascade.value.error = e.message === 'galat' || e instanceof TypeError ? 'Daftar wilayah belum dapat dimuat.' : e.message;
    } finally { cascade.value.busy = null; }
}
async function chooseLevel(i, code) {
    const levels = cascade.value.levels;
    levels[i].value = code;
    for (let j = i + 1; j < levels.length; j++) { levels[j].value = ''; levels[j].options = []; }
    if (!code) return;
    if (i < 3) { await loadLevel(i + 1); return; }
    const name = (k) => levels[k].options.find((o) => o.code === levels[k].value)?.name ?? '';
    select({ name: name(3), admin: `${name(2)}, ${name(1)}, ${name(0)}`, province: name(0), latitude: -2.5, longitude: 118, adm4: code }, 'adm4');
}
function toggleCascade() {
    adm4Open.value = !adm4Open.value;
    if (adm4Open.value && !cascade.value.levels[0].options.length) loadLevel(0);
}

function toggleCompare(l) {
    const k = locationKey(l);
    if (compare.value.some((c) => locationKey(c) === k)) compare.value = compare.value.filter((c) => locationKey(c) !== k);
    else if (compare.value.length >= MAX_COMPARE) notice.value = `Perbandingan maksimal ${MAX_COMPARE} lokasi.`;
    else { compare.value = [...compare.value, l]; w.load(l); }
    syncUrl();
}
const inCompare = (l) => compare.value.some((c) => locationKey(c) === locationKey(l));

function syncUrl() {
    const q = new URLSearchParams();
    if (loc.value && loc.value.from !== 'device') {
        if (loc.value.adm4) q.set('adm4', loc.value.adm4);
        else { q.set('lat', loc.value.latitude.toFixed(2)); q.set('lon', loc.value.longitude.toFixed(2)); }
        q.set('nama', loc.value.name);
        if (loc.value.province) q.set('prov', loc.value.province);
    }
    compare.value.filter((c) => c.from !== 'device' && !c.adm4).forEach((c) => q.append('b', `${c.latitude.toFixed(2)},${c.longitude.toFixed(2)},${c.name}`));
    const s = q.toString();
    try { history.replaceState(null, '', s ? `/cuaca?${s}` : '/cuaca'); } catch { /* tautan tidak diperbarui */ }
}

async function copyLink() {
    syncUrl();
    try { await navigator.clipboard.writeText(location.href); notice.value = 'Tautan disalin. Tautan hanya berisi lokasi yang Anda pilih, bukan lokasi perangkat atau data akun.'; } catch { notice.value = 'Penyalinan tidak tersedia. Salin alamat dari bilah peramban.'; }
}

function startRename(f) { renaming.value = locationKey(f); renameText.value = f.name; }
function finishRename(f) { w.renameFavorite(f, renameText.value); renaming.value = null; }

async function loadNearby() {
    if (!loc.value) return;
    if (nearby.value.state === 'idle') {
        nearby.value = { state: 'loading', events: [], camps: [] };
        try {
            const [e, c] = await Promise.all([
                fetch('/api/v1/disasters?hours=168&limit=300', { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : Promise.reject())),
                fetch('/api/v1/camps', { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : Promise.reject())),
            ]);
            nearby.value = { state: 'ok', events: e.features, camps: c.features };
        } catch {
            nearby.value = { state: 'error', events: [], camps: [] };
        }
    }
}
const regional = computed(() => {
    if (!loc.value || nearby.value.state !== 'ok') return null;
    const { latitude: la, longitude: lo } = loc.value;
    const events = nearby.value.events.filter((f) => distanceKm(la, lo, f.geometry.coordinates[1], f.geometry.coordinates[0]) <= 200);
    const camps = nearby.value.camps.filter((f) => distanceKm(la, lo, f.geometry.coordinates[1], f.geometry.coordinates[0]) <= 50);
    const top = events.reduce((a, b) => ((b.properties.magnitude ?? 0) > (a?.properties.magnitude ?? 0) ? b : a), null);

    return { events, camps, top };
});

async function openUpdates() {
    tab.value = 'peringatan';
    await loadNational();
}
async function loadNational() {
    national.value = { ...national.value, state: 'loading' };
    try {
        const r = await fetch('/api/v1/weather-alerts?state=all', { headers: { Accept: 'application/json' } });
        const body = await r.json();
        national.value = r.ok && body.status === 'ok' ? { state: 'ok', data: body, message: '' } : { state: 'error', data: null, message: body?.error?.message ?? 'Peringatan dini belum dapat diambil.' };
    } catch {
        national.value = { state: 'error', data: null, message: navigator.onLine === false ? 'Perangkat sedang luring.' : 'Peringatan dini belum dapat diambil.' };
    }
}
const favoriteProvinces = computed(() => w.favorites.value.map((f) => f.province).filter(Boolean));
const updates = computed(() => {
    const list = national.value.data?.alerts ?? [];
    const filtered = onlyFavoriteAlerts.value ? list.filter((a) => favoriteProvinces.value.some((p) => a.area?.toLowerCase().includes(p.toLowerCase()))) : list;

    return { active: filtered.filter((a) => a.state === 'active' || a.state === 'upcoming'), ended: filtered.filter((a) => a.state === 'expired' || a.state === 'cancelled') };
});
const unreadCount = computed(() => (national.value.data?.alerts ?? []).filter((a) => (a.state === 'active' || a.state === 'upcoming') && !readIds.value.has(a.id)).length);
function markRead(id) {
    if (readIds.value.has(id)) return;
    readIds.value = new Set([...readIds.value, id]);
    try { localStorage.setItem(READ_KEY, JSON.stringify([...readIds.value].slice(-200))); } catch { /* status baca hanya berlaku sementara */ }
}
function toggleAlert(a) { expandedAlert.value = expandedAlert.value === a.id ? null : a.id; markRead(a.id); }
const stateLabel = { active: 'Aktif', upcoming: 'Akan berlaku', expired: 'Berakhir', cancelled: 'Dibatalkan' };
const coverageText = (a) => (a.coverage === 'polygon' ? 'Titik ini berada di dalam area peringatan.' : a.coverage === 'province' ? 'Peringatan mencakup sebagian wilayah provinsi ini (tingkat provinsi; belum tentu mencakup titik ini).' : '');

function onNet() { online.value = navigator.onLine !== false; }

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30000);
    window.addEventListener('online', onNet); window.addEventListener('offline', onNet); onNet();
    try { readIds.value = new Set(JSON.parse(localStorage.getItem(READ_KEY) ?? '[]')); } catch { /* kosong */ }
    const q = new URLSearchParams(location.search);
    const lat = parseFloat(q.get('lat')), lon = parseFloat(q.get('lon')), adm4 = q.get('adm4');
    for (const b of q.getAll('b').slice(0, MAX_COMPARE)) {
        const [a, o, ...n] = b.split(',');
        if (Number.isFinite(+a) && Number.isFinite(+o)) { const c = { name: n.join(',').slice(0, 60) || 'Lokasi', admin: '', latitude: +a, longitude: +o, from: 'url' }; compare.value.push(c); w.load(c); }
    }
    if (adm4 && /^\d{2}\.\d{2}\.\d{2}\.\d{4}$/.test(adm4)) select({ name: q.get('nama') ?? `Kode ${adm4}`, admin: '', province: q.get('prov'), latitude: -2.5, longitude: 118, adm4 }, 'url');
    else if (Number.isFinite(lat) && Number.isFinite(lon)) select({ name: (q.get('nama') ?? 'Lokasi dipilih').slice(0, 80), admin: '', province: q.get('prov'), latitude: lat, longitude: lon }, 'url');
    else if (w.defaultFavorite.value) select(w.defaultFavorite.value, 'favorite');
});
onBeforeUnmount(() => { clearInterval(clock); window.removeEventListener('online', onNet); window.removeEventListener('offline', onNet); });
watch(queryText, (t) => w.search(t));

const clockFor = (d) => formatLocalTime(d.current.time, d.timezone, d.tz_label);
const COMPARE_ROWS =[['Kondisi', (c) => c.condition ?? dash], ['Suhu', (c) => num(c.temperature, '°C', 1)], ['Terasa seperti', (c) => num(c.feels_like, '°C', 1)], ['Kelembapan', (c) => num(c.humidity, '%')], ['Angin', (c) => num(c.wind_speed, 'km/jam', 1)], ['Peluang hujan', (c) => num(c.rain_probability, '%')], ['Curah hujan', (c) => num(c.precipitation, 'mm', 1)]];
const compareData = computed(() => compare.value.map((c) => ({ loc: c, state: w.states[locationKey(c)] })));
const compareMixed = computed(() => new Set(compareData.value.map((c) => c.state?.data?.provider).filter(Boolean)).size > 1);
</script>

<template>
    <Head title="Cuaca wilayah" />
    <div class="wx">
        <a class="skip" href="#wx-main">Lewati ke isi cuaca</a>
        <header class="wx-header">
            <Link href="/" class="identity" aria-label="IGI, Info Gempa Indonesia"><BrandMark :size="34" /><span><b>IGI</b><small>Info Gempa Indonesia</small></span></Link>
            <nav aria-label="Navigasi utama"><Link href="/">Pantauan</Link><Link href="/cuaca" aria-current="page">Cuaca</Link><Link href="/komando">Komando</Link><Link href="/berita">Berita</Link><Link href="/panduan">Panduan</Link><Link href="/tentang">Tentang</Link></nav>
            <button class="icon-btn" type="button" :aria-pressed="big" aria-label="Perbesar ukuran teks" @click="toggle"><Icon name="text" :size="18" /></button>
            <Link :href="workHref" class="account"><Icon name="user" :size="16" /><span>{{ page.props.auth?.user ? 'Ruang kerja' : 'Petugas' }}</span></Link>
        </header>

        <div class="wx-body">
            <aside class="wx-side" aria-label="Pilih lokasi">
                <div class="side-scroll">
                    <span class="eyebrow">CUACA WILAYAH</span>
                    <h1>Cuaca di mana saja</h1>
                    <label class="search"><Icon name="search" :size="18" /><span class="sr-only">Cari kota, kabupaten, atau tempat</span><input v-model="queryText" type="search" autocomplete="off" placeholder="Cari kota, kabupaten, atau tempat" aria-controls="wx-results" /></label>
                    <div id="wx-results" class="results" aria-live="polite">
                        <p v-if="w.searching.value" class="hint">Mencari…</p>
                        <p v-else-if="w.searchError.value" class="error" role="alert">{{ w.searchError.value }}</p>
                        <p v-else-if="w.searched.value && !w.results.value.length" class="hint">Tidak ada tempat bernama “{{ w.searched.value }}” di Indonesia. Periksa ejaan atau pilih titik di peta.</p>
                        <ul v-if="w.results.value.length"><li v-for="r in w.results.value" :key="r.id"><button type="button" @click="select(r)"><b>{{ r.name }}</b><small>{{ r.admin || 'Indonesia' }} · {{ r.latitude.toFixed(2) }}, {{ r.longitude.toFixed(2) }}</small></button></li></ul>
                    </div>
                    <div class="side-actions">
                        <button type="button" @click="useDevice"><Icon name="pin" :size="16" />Gunakan lokasi perangkat</button>
                        <button type="button" :aria-expanded="adm4Open" @click="toggleCascade">Pilih per wilayah (BMKG)</button>
                    </div>
                    <div v-if="adm4Open" class="adm4" role="group" aria-label="Pilih wilayah bertingkat">
                        <label v-for="(lv, i) in cascade.levels" :key="i" v-show="i === 0 || cascade.levels[i - 1].value">
                            {{ LEVEL_LABELS[i] }}
                            <select :value="lv.value" :disabled="cascade.busy === i || !lv.options.length" @change="chooseLevel(i, $event.target.value)">
                                <option value="">{{ cascade.busy === i ? 'Memuat…' : `Pilih ${LEVEL_LABELS[i].toLowerCase()}` }}</option>
                                <option v-for="o in lv.options" :key="o.code" :value="o.code">{{ o.name }}</option>
                            </select>
                        </label>
                        <p v-if="cascade.error" class="error" role="alert">{{ cascade.error }} <button type="button" @click="loadLevel(0)">Coba lagi</button></p>
                        <small v-if="cascade.catalog">Katalog: {{ cascade.catalog.decree }} ({{ cascade.catalog.counts.desa_kelurahan.toLocaleString('id-ID') }} desa/kelurahan). Prakiraan BMKG tersedia per desa/kelurahan; bila BMKG belum punya data untuk satu desa, itu akan dinyatakan.</small>
                    </div>
                    <p v-if="notice" class="hint" role="status">{{ notice }}</p>

                    <section class="favs" aria-labelledby="fav-h">
                        <div class="fav-head"><h2 id="fav-h">Favorit <span>{{ w.favorites.value.length }}/{{ MAX_FAVORITES }}</span></h2><button v-if="w.favorites.value.length > 1" type="button" @click="w.sortFavorites()">Urutkan A–Z</button></div>
                        <p v-if="w.favoriteError.value" class="error" role="alert">{{ w.favoriteError.value }}</p>
                        <p v-if="!w.favorites.value.length" class="hint">Simpan lokasi yang sering Anda lihat. Favorit hanya disimpan di perangkat ini.</p>
                        <ul>
                            <li v-for="f in w.favorites.value" :key="locationKey(f)" :class="{ active: key === locationKey(f) }">
                                <form v-if="renaming === locationKey(f)" @submit.prevent="finishRename(f)"><label class="sr-only" :for="`rn-${locationKey(f)}`">Nama favorit</label><input :id="`rn-${locationKey(f)}`" v-model="renameText" maxlength="60" /><button type="submit">Simpan</button></form>
                                <template v-else>
                                    <button type="button" class="fav-main" @click="select(f, 'favorite')"><b>{{ f.name }}</b><small>{{ f.admin || f.province || 'Indonesia' }}<template v-if="f.default"> · bawaan</template></small></button>
                                    <span class="fav-tools">
                                        <button type="button" :aria-pressed="inCompare(f)" :title="inCompare(f) ? 'Keluarkan dari perbandingan' : 'Bandingkan'" aria-label="Bandingkan" @click="toggleCompare(f)">⇄</button>
                                        <button type="button" :aria-pressed="f.default" title="Jadikan lokasi bawaan" aria-label="Jadikan lokasi bawaan" @click="w.setDefault(f)">★</button>
                                        <button type="button" title="Ubah nama" aria-label="Ubah nama" @click="startRename(f)">✎</button>
                                        <button type="button" title="Naik" aria-label="Geser ke atas" @click="w.moveFavorite(f, -1)">↑</button>
                                        <button type="button" title="Hapus" aria-label="Hapus dari favorit" @click="w.removeFavorite(f)">×</button>
                                    </span>
                                </template>
                            </li>
                        </ul>
                    </section>
                </div>
                <div class="side-map"><WeatherMap :point="loc" :markers="compare" :alerts="alertGeometries" @pick="pickOnMap" /></div>
            </aside>

            <main id="wx-main" class="wx-main">
                <div v-if="!online" class="banner warn" role="status">Perangkat luring. Data yang tampil adalah muatan terakhir dan mungkin sudah usang.</div>
                <div class="tabs" role="tablist" aria-label="Tampilan cuaca">
                    <button role="tab" :aria-selected="tab === 'cuaca'" type="button" @click="tab = 'cuaca'">Cuaca</button>
                    <button role="tab" :aria-selected="tab === 'peringatan'" type="button" @click="openUpdates">Peringatan dini<span v-if="unreadCount" class="badge">{{ unreadCount }}</span></button>
                    <button role="tab" :aria-selected="tab === 'bandingkan'" type="button" @click="tab = 'bandingkan'">Bandingkan<span v-if="compare.length" class="badge">{{ compare.length }}</span></button>
                </div>

                <template v-if="tab === 'cuaca'">
                    <div v-if="!loc" class="empty"><h2>Pilih lokasi</h2><p>Cari nama kota atau kabupaten, klik titik di peta, atau izinkan lokasi perangkat. Data berasal dari BMKG dan Open-Meteo.</p></div>

                    <template v-else>
                        <div class="loc-head">
                            <div><h2>{{ loc.name }}</h2><p>{{ loc.admin || 'Indonesia' }} · {{ loc.latitude.toFixed(2) }}, {{ loc.longitude.toFixed(2) }}<template v-if="data"> · {{ tzLabel }}</template></p></div>
                            <div class="loc-actions">
                                <button type="button" :aria-pressed="w.isFavorite(loc)" @click="w.isFavorite(loc) ? w.removeFavorite(loc) : w.addFavorite(loc)">{{ w.isFavorite(loc) ? '★ Favorit' : '☆ Simpan favorit' }}</button>
                                <button type="button" :aria-pressed="inCompare(loc)" @click="toggleCompare(loc)">⇄ Bandingkan</button>
                                <button type="button" @click="copyLink">Salin tautan</button>
                                <button type="button" :disabled="st?.status === 'loading'" @click="w.load(loc, { force: true }); w.loadAlerts(loc)">↻ Perbarui</button>
                            </div>
                        </div>

                        <div v-if="alertState?.ok === false" class="banner warn" role="status">Peringatan dini BMKG belum dapat dicek ({{ alertState.message }}). Ini bukan berarti tidak ada peringatan; periksa <a href="https://www.bmkg.go.id" target="_blank" rel="noopener noreferrer">bmkg.go.id</a>.</div>
                        <div v-else-if="matchedAlerts.length" class="banner alert" role="status">
                            <b>{{ matchedAlerts.length }} peringatan dini BMKG berlaku untuk lokasi ini</b>
                            <ul><li v-for="a in matchedAlerts" :key="a.id"><button type="button" :aria-expanded="expandedAlert === a.id" @click="toggleAlert(a)">{{ a.title }}</button> <small>s.d. {{ formatLocalTime(a.expires, tz, tzLabel) }}</small>
                                <div v-if="expandedAlert === a.id" class="alert-body"><p>{{ coverageText(a) }}</p><p class="pre">{{ a.description }}</p><p v-if="a.instruction" class="pre">{{ a.instruction }}</p><a :href="a.source_url" target="_blank" rel="noopener noreferrer">Dokumen CAP BMKG ↗</a></div></li></ul>
                        </div>
                        <p v-else-if="alertState?.ok" class="quiet">Tidak ada peringatan dini BMKG aktif untuk lokasi ini · diperiksa {{ formatLocalTime(alertState.data.checked_at, tz, tzLabel) }}. Tidak adanya peringatan bukan jaminan kondisi aman.</p>

                        <div v-if="st?.status === 'loading' && !data" class="empty" aria-busy="true"><p>Memuat cuaca…</p></div>
                        <div v-else-if="st?.status === 'error' && !data" class="empty"><h2>Cuaca belum dapat dimuat</h2><p role="alert">{{ st.error.message }}</p><button type="button" @click="w.load(loc, { force: true })">Coba lagi</button></div>

                        <template v-if="data">
                            <div class="provenance">
                                <b>{{ data.fallback ? 'Cadangan: ' : '' }}{{ data.label }}</b>
                                <span>Sumber: {{ data.provider === 'bmkg' ? 'BMKG' : 'Open-Meteo' }}</span>
                                <span>Diambil {{ formatAgo(data.fetched_at, now) }}</span>
                                <span v-if="data.issued_at">Diterbitkan {{ formatLocalDay(data.issued_at, tz, { day: 'numeric', month: 'short' }) }} {{ clock24(data.issued_at) }}</span>
                                <span v-else>Waktu terbit tidak diberikan penyedia</span>
                                <span>Berlaku {{ formatLocalDay(data.valid_from, tz) }} – {{ formatLocalDay(data.valid_to, tz) }}</span>
                            </div>
                            <div v-if="data.fallback" class="banner warn" role="status">{{ data.fallback.note }}</div>
                            <div v-if="data.is_stale" class="banner warn" role="status">Data ini sudah {{ Math.round(data.cache_age_seconds / 60) }} menit dan penyedia belum menjawab pembaruan. Ditampilkan apa adanya.</div>
                            <div v-if="st.status === 'error'" class="banner warn" role="alert">Pembaruan gagal: {{ st.error.message }}</div>

                            <section class="summary" aria-label="Ringkasan cuaca">
                                <div class="now"><span class="temp">{{ num(data.current.temperature, '', 1) }}<small>°C</small></span><span class="cond">{{ data.current.condition ?? dash }}</span><small>{{ data.provider === 'bmkg' ? 'Slot prakiraan 3 jam' : 'Perkiraan model' }} · {{ clock24(data.current.time) }}</small></div>
                                <dl>
                                    <div><dt>Terasa seperti</dt><dd>{{ num(data.current.feels_like, '°C', 1) }}</dd></div>
                                    <div><dt>Kelembapan</dt><dd>{{ num(data.current.humidity, '%') }}</dd></div>
                                    <div><dt>Angin</dt><dd>{{ num(data.current.wind_speed, 'km/jam', 1) }}</dd></div>
                                    <div><dt>Peluang hujan</dt><dd>{{ num(data.current.rain_probability, '%') }}</dd></div>
                                    <div><dt>Curah hujan</dt><dd>{{ num(data.current.precipitation, 'mm', 1) }}</dd></div>
                                    <div><dt>Indeks UV</dt><dd>{{ num(data.current.uv, '', 1) }}</dd></div>
                                    <div><dt>Matahari terbit</dt><dd>{{ clock24(data.daily[0]?.sunrise) }}</dd></div>
                                    <div><dt>Matahari terbenam</dt><dd>{{ clock24(data.daily[0]?.sunset) }}</dd></div>
                                </dl>
                            </section>

                            <section class="block" aria-labelledby="h-hour">
                                <div class="block-head"><h3 id="h-hour">Suhu {{ hourly48.length }} {{ data.provider === 'bmkg' ? 'slot (3 jam)' : 'jam' }} ke depan</h3><div class="seg" role="group" aria-label="Bentuk tampilan"><button type="button" :aria-pressed="view === 'chart'" @click="view = 'chart'">Grafik</button><button type="button" :aria-pressed="view === 'table'" @click="view = 'table'">Tabel</button></div></div>
                                <template v-if="view === 'chart' && chart">
                                    <svg class="chart" viewBox="0 0 720 230" role="img" :aria-label="chartLabel">
                                        <line x1="44" :y1="chart.yTop" x2="704" :y2="chart.yTop" class="grid" /><line x1="44" :y1="chart.yBottom" x2="704" :y2="chart.yBottom" class="grid" />
                                        <text x="38" :y="chart.yTop + 4" class="axis" text-anchor="end">{{ chart.hi }}°</text><text x="38" :y="chart.yBottom + 4" class="axis" text-anchor="end">{{ chart.lo }}°</text>
                                        <rect v-for="(b, i) in chart.bars" :key="i" :x="b.x" :y="b.y" width="6" :height="b.h" class="rain" />
                                        <polyline v-for="(s, i) in chart.segments" :key="i" :points="s.join(' ')" class="line" />
                                        <text v-for="t in chart.ticks" :key="t.i" :x="t.x" y="220" class="axis" text-anchor="middle">{{ t.label }}</text>
                                    </svg>
                                    <p class="legend"><i class="l-line"></i>Suhu (°C) <i class="l-rain"></i>{{ hasRainProb ? 'Peluang hujan (%)' : 'Curah hujan (mm)' }} · jam {{ tzLabel }}</p>
                                </template>
                                <p v-else-if="view === 'chart'" class="hint">Data tidak cukup untuk grafik; lihat tabel.</p>
                                <div v-if="view === 'table' || !chart" class="table-wrap"><table><caption class="sr-only">Prakiraan per jam di {{ loc.name }}</caption><thead><tr><th scope="col">Waktu ({{ tzLabel }})</th><th scope="col">Kondisi</th><th scope="col">Suhu</th><th scope="col">Hujan</th><th scope="col">Angin</th><th scope="col">Lembap</th></tr></thead><tbody><tr v-for="h in hourly48" :key="h.time"><th scope="row">{{ formatLocalDay(h.time, tz, { day: 'numeric', month: 'short' }) }} {{ clock24(h.time).replace(` ${tzLabel}`, '') }}</th><td>{{ h.condition ?? dash }}</td><td>{{ num(h.temperature, '°C', 1) }}</td><td>{{ h.rain_probability !== null ? num(h.rain_probability, '%') : num(h.precipitation, 'mm', 1) }}</td><td>{{ num(h.wind_speed, 'km/jam', 1) }}</td><td>{{ num(h.humidity, '%') }}</td></tr></tbody></table></div>
                            </section>

                            <section class="block" aria-labelledby="h-day">
                                <h3 id="h-day">{{ data.daily.length }} hari ke depan</h3>
                                <ul class="days"><li v-for="d in data.daily" :key="d.date"><b>{{ formatLocalDay(d.date + 'T12:00:00Z', 'UTC', { weekday: 'short', day: 'numeric', month: 'short' }) }}</b><span>{{ d.condition ?? dash }}</span><span>{{ num(d.temp_min, '°', 0) }} / {{ num(d.temp_max, '°C', 0) }}</span><span>{{ d.rain_probability !== null ? `hujan ${num(d.rain_probability, '%')}` : `${num(d.precipitation, 'mm', 1)}` }}</span><small v-if="d.partial">data parsial</small></li></ul>
                            </section>

                            <section class="block" aria-labelledby="h-reg">
                                <h3 id="h-reg">Ringkasan wilayah</h3>
                                <p v-if="nearby.state === 'loading'" class="hint">Memuat data gempa dan posko…</p>
                                <p v-else-if="nearby.state === 'error'" class="hint">Data gempa dan posko belum dapat dimuat.</p>
                                <ul v-else-if="regional" class="reg">
                                    <li>{{ regional.events.length }} gempa tercatat dalam radius 200 km dari titik ini selama 7 hari terakhir<template v-if="regional.top">; terbesar M {{ formatMagnitude(regional.top.properties.magnitude) }} ({{ regional.top.properties.location }})</template>. <Link href="/">Buka pantauan</Link></li>
                                    <li>{{ regional.camps.length }} posko terdaftar dalam radius 50 km<template v-if="regional.camps.length">: {{ regional.camps.slice(0, 3).map((c) => c.properties.name).join(', ') }}</template>. Kapasitas dan penghuni adalah data tercatat, bukan jaminan ketersediaan.</li>
                                    <li><Link href="/panduan">Panduan kesiapsiagaan</Link> · Nomor darurat 112.</li>
                                </ul>
                                <p class="hint">Radius adalah perkiraan jarak, bukan peta guncangan atau zona bahaya resmi.</p>
                            </section>
                        </template>
                    </template>
                </template>

                <section v-else-if="tab === 'peringatan'" class="block" aria-labelledby="h-upd">
                    <div class="block-head"><h2 id="h-upd">Pusat pembaruan: peringatan dini cuaca BMKG</h2><label class="check"><input v-model="onlyFavoriteAlerts" type="checkbox" /> Hanya provinsi favorit saya</label></div>
                    <p v-if="national.state === 'loading'" class="hint">Memuat…</p>
                    <div v-else-if="national.state === 'error'" class="banner warn" role="alert">{{ national.message }} Ini bukan berarti tidak ada peringatan. <button type="button" @click="loadNational">Coba lagi</button></div>
                    <template v-else-if="national.data">
                        <p class="hint">Diperiksa {{ formatAgo(national.data.checked_at, now) }}<template v-if="national.data.is_stale"> · pembaruan terakhir gagal, ini data lama</template>. {{ national.data.attribution }}</p>
                        <h3>Aktif ({{ updates.active.length }})</h3>
                        <p v-if="!updates.active.length" class="hint">Tidak ada peringatan aktif{{ onlyFavoriteAlerts ? ' untuk provinsi favorit Anda' : '' }} pada pemeriksaan ini.</p>
                        <ul class="alert-list"><li v-for="a in updates.active" :key="a.id" :class="{ unread: !readIds.has(a.id) }"><button type="button" :aria-expanded="expandedAlert === a.id" @click="toggleAlert(a)"><b>{{ a.title }}</b><small>{{ a.issuer }} · {{ stateLabel[a.state] }} · s.d. {{ formatLocalDay(a.expires, 'Asia/Jakarta', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</small></button>
                            <div v-if="expandedAlert === a.id" class="alert-body"><p><b>Area:</b> {{ a.area }}</p><p class="pre">{{ a.description }}</p><p v-if="a.instruction" class="pre">{{ a.instruction }}</p><a :href="a.source_url" target="_blank" rel="noopener noreferrer">Dokumen CAP BMKG ↗</a></div></li></ul>
                        <h3>Berakhir atau dibatalkan ({{ updates.ended.length }})</h3>
                        <ul class="alert-list ended"><li v-for="a in updates.ended" :key="a.id"><button type="button" :aria-expanded="expandedAlert === a.id" @click="toggleAlert(a)"><b>{{ a.title }}</b><small>{{ stateLabel[a.state] }}</small></button><div v-if="expandedAlert === a.id" class="alert-body"><p class="pre">{{ a.description }}</p></div></li></ul>
                        <p class="hint">Notifikasi dorong peramban belum tersedia di IGI. Buka halaman ini untuk memeriksa pembaruan.</p>
                    </template>
                </section>

                <section v-else class="block" aria-labelledby="h-cmp">
                    <h2 id="h-cmp">Bandingkan lokasi (maksimal {{ MAX_COMPARE }})</h2>
                    <p v-if="!compare.length" class="hint">Tekan ⇄ pada lokasi untuk menambahkannya. Tiap kolom memakai zona waktunya sendiri.</p>
                    <div v-else class="table-wrap">
                        <p v-if="compareMixed" class="banner warn">Lokasi memakai penyedia berbeda (BMKG dan Open-Meteo); nilai tidak sepenuhnya sebanding.</p>
                        <table><caption class="sr-only">Perbandingan cuaca lokasi</caption><thead><tr><th scope="col">&nbsp;</th><th v-for="c in compareData" :key="locationKey(c.loc)" scope="col">{{ c.loc.name }}<small>{{ c.state?.data?.tz_label ?? '' }} · {{ c.state?.data?.provider === 'bmkg' ? 'BMKG' : 'Open-Meteo' }}</small><button type="button" class="x" aria-label="Keluarkan" @click="toggleCompare(c.loc)">×</button></th></tr></thead>
                            <tbody>
                                <tr><th scope="row">Waktu data</th><td v-for="c in compareData" :key="locationKey(c.loc)">{{ c.state?.data ? clockFor(c.state.data) : dash }}</td></tr>
                                <tr v-for="[label, fn] in COMPARE_ROWS" :key="label"><th scope="row">{{ label }}</th><td v-for="c in compareData" :key="locationKey(c.loc)"><template v-if="c.state?.data">{{ fn(c.state.data.current) }}</template><template v-else-if="c.state?.status === 'error'">gagal</template><template v-else>memuat…</template></td></tr>
                            </tbody></table>
                    </div>
                </section>
                <footer class="wx-foot">Prakiraan dan peringatan: BMKG. Estimasi koordinat: Open-Meteo (CC BY 4.0, tingkat gratis non-komersial). Pencarian tempat: GeoNames. Untuk arahan keselamatan ikuti BMKG, BPBD, dan BNPB.</footer>
            </main>
        </div>
    </div>
</template>

<style scoped>
.wx{--navy:#17334c;--blue:#215b84;--edge:#dce2e7;--muted:#657481;position:fixed;inset:0;display:flex;flex-direction:column;background:#eef1f3;color:#233444;font-family:'Figtree Variable',Arial,sans-serif;font-size:14px;line-height:1.5}
.wx button{cursor:pointer;touch-action:manipulation}.wx button:disabled{opacity:.5;cursor:wait}.wx a:hover{text-decoration:underline;text-underline-offset:3px}.skip{position:absolute;left:12px;top:-80px;z-index:2000;padding:12px;background:#fff}.skip:focus{top:12px}
.wx-header{height:72px;flex-shrink:0;display:flex;align-items:center;gap:22px;padding:0 24px;background:#fff;border-bottom:1px solid var(--edge);z-index:900}.identity{display:flex;gap:9px;align-items:center;min-width:190px}.identity b{font-size:23px;line-height:1;letter-spacing:-1px}.identity-dot{color:#29709b}.identity small{display:block;font-size:10px;color:var(--muted);margin-top:3px}.wx-header nav{display:flex;gap:22px;height:100%;margin-left:auto;white-space:nowrap}.wx-header nav a{display:flex;align-items:center;font-size:13px;font-weight:500;color:#687784;border-bottom:3px solid transparent;padding-top:3px}.wx-header nav a[aria-current]{color:var(--navy);border-color:#296a93;font-weight:650}.icon-btn{padding:8px;border:1px solid var(--edge);border-radius:4px;min-width:36px;min-height:36px}.account{display:flex;align-items:center;gap:7px;font-size:12px;white-space:nowrap}
.wx-body{flex:1;min-height:0;display:grid;grid-template-columns:360px minmax(0,1fr)}.wx-side{display:flex;flex-direction:column;min-height:0;background:#fff;border-right:1px solid #cbd4db}.side-scroll{flex:1;min-height:0;overflow:auto;padding:20px 22px;overscroll-behavior:contain}.side-map{height:230px;flex-shrink:0;border-top:1px solid var(--edge);position:relative;isolation:isolate;overflow:hidden}
.eyebrow{font-size:9px;font-weight:650;letter-spacing:1.2px;color:#72818c}h1{font-size:22px;letter-spacing:-.5px;line-height:1.3;font-weight:650;margin:5px 0 14px}.search{display:flex;align-items:center;gap:9px;border:1px solid #d5dde4;border-radius:4px;padding:0 11px;height:42px;color:var(--muted)}.search input{width:100%;min-width:0;background:transparent;font-size:14px;outline-offset:4px}
.results ul{border:1px solid var(--edge);border-radius:4px;margin-top:8px;max-height:260px;overflow:auto}.results li+li{border-top:1px solid var(--edge)}.results button{display:block;width:100%;text-align:left;padding:10px 12px;min-height:44px}.results button:hover,.fav-main:hover{background:#f2f6fa}.results small,.fav-main small{display:block;font-size:11px;color:var(--muted)}.hint{font-size:12px;color:var(--muted);margin:8px 0;line-height:1.6}.error{font-size:12px;color:#9a3b22;margin:8px 0}
.side-actions{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0}.side-actions button{display:flex;align-items:center;gap:6px;border:1px solid #bdccd7;border-radius:4px;padding:0 11px;min-height:40px;font-size:12px;color:var(--blue)}.adm4{border:1px solid var(--edge);border-radius:4px;padding:12px;margin-bottom:10px;display:grid;gap:8px;font-size:12px}.adm4 select{width:100%;margin-top:4px;border:1px solid #d5dde4;border-radius:4px;padding:9px;min-height:44px;background:#fff}.adm4 small{color:var(--muted);line-height:1.6}
.favs{margin-top:18px;border-top:1px solid var(--edge);padding-top:14px}.fav-head{display:flex;justify-content:space-between;align-items:center}.favs h2{font-size:13px;font-weight:650}.favs h2 span{font-weight:400;color:var(--muted);font-size:11px}.fav-head button{font-size:11px;color:var(--blue);min-height:32px}.favs li{display:flex;align-items:center;border-bottom:1px solid var(--edge)}.favs li.active{background:#f2f6fa}.fav-main{flex:1;text-align:left;padding:9px 6px;min-height:44px;min-width:0}.fav-main b{display:block;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.fav-tools{display:flex}.fav-tools button{min-width:32px;min-height:44px;color:#6b7b88}.fav-tools [aria-pressed=true]{color:var(--blue);font-weight:700}.favs form{display:flex;gap:6px;padding:6px 0;width:100%}.favs form input{flex:1;border:1px solid #d5dde4;border-radius:4px;padding:8px;min-width:0}.favs form button{color:var(--blue);padding:0 8px}
.wx-main{overflow:auto;padding:20px 28px 40px;min-width:0;overscroll-behavior:contain}.tabs{display:flex;gap:2px;border-bottom:1px solid var(--edge);margin-bottom:18px}.tabs button{padding:11px 16px;min-height:44px;font-size:13px;color:var(--muted);border-bottom:2px solid transparent}.tabs [aria-selected=true]{color:var(--blue);border-color:var(--blue);font-weight:650}.badge{margin-left:6px;background:#a3123a;color:#fff;border-radius:3px;font-size:10px;padding:1px 5px}
.banner{border:1px solid #e6c997;background:#fff8e9;border-radius:4px;padding:10px 12px;font-size:12px;margin:10px 0}.banner.alert{background:#fff2e9;border-color:#e0a477}.banner ul{margin-top:6px}.banner li{padding:4px 0}.banner li>button{text-decoration:underline;text-align:left;font-weight:600;min-height:32px}.alert-body{margin-top:6px;padding:10px;background:#fff;border:1px solid var(--edge);border-radius:4px;font-size:12px}.alert-body a{color:var(--blue);text-decoration:underline}.pre{white-space:pre-line;margin:6px 0;line-height:1.7}.quiet{font-size:12px;color:var(--muted);margin:8px 0 14px}
.empty{padding:36px 4px;color:var(--muted);max-width:520px}.empty h2{font-size:17px;color:#334d62;margin-bottom:8px}.empty button{margin-top:12px;text-decoration:underline;color:var(--blue);min-height:44px}
.loc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}.loc-head h2{font-size:22px;font-weight:650;letter-spacing:-.4px}.loc-head p{font-size:12px;color:var(--muted)}.loc-actions{display:flex;gap:6px;flex-wrap:wrap}.loc-actions button{border:1px solid #bdccd7;border-radius:4px;padding:0 12px;min-height:40px;font-size:12px;color:var(--blue);background:#fff}.loc-actions [aria-pressed=true]{background:#edf3f8;font-weight:650}
.provenance{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:11px;color:var(--muted);margin:14px 0;padding:10px 12px;background:#fff;border:1px solid var(--edge);border-radius:4px}.provenance b{color:var(--navy)}
.summary{display:grid;grid-template-columns:230px 1fr;gap:20px;background:#fff;border:1px solid var(--edge);border-radius:5px;padding:18px 20px}.now{display:flex;flex-direction:column;gap:2px}.temp{font-size:44px;font-weight:600;letter-spacing:-1.5px;line-height:1.1;font-variant-numeric:tabular-nums}.temp small{font-size:18px;font-weight:500;margin-left:2px}.cond{font-size:15px;font-weight:600}.now small{font-size:11px;color:var(--muted)}.summary dl{display:grid;grid-template-columns:repeat(4,1fr);gap:12px 16px}.summary dt{font-size:11px;color:var(--muted)}.summary dd{font-size:15px;font-weight:600;font-variant-numeric:tabular-nums}
.block{background:#fff;border:1px solid var(--edge);border-radius:5px;padding:16px 20px;margin-top:16px}.block h2,.block h3{font-size:14px;font-weight:650}.block-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}.seg{display:flex;border:1px solid #bdccd7;border-radius:4px;overflow:hidden}.seg button{padding:0 14px;min-height:36px;font-size:12px;background:#fff}.seg [aria-pressed=true]{background:#edf3f8;color:var(--blue);font-weight:650}.check{font-size:12px;display:flex;gap:6px;align-items:center;min-height:36px}
.chart{width:100%;height:auto;max-height:260px}.chart .grid{stroke:#e4e9ed;stroke-width:1}.chart .axis{font-size:10px;fill:#6a7a86}.chart .line{fill:none;stroke:#215b84;stroke-width:2.2;stroke-linejoin:round}.chart .rain{fill:#9bbad0}.legend{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:6px;flex-wrap:wrap}.l-line,.l-rain{display:inline-block;width:14px;height:3px;background:#215b84}.l-rain{background:#9bbad0;height:8px}
.table-wrap{overflow:auto;max-height:420px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:7px 10px;text-align:left;border-bottom:1px solid var(--edge);white-space:nowrap}thead th{position:sticky;top:0;background:#f7f9fa;font-weight:650;vertical-align:top}th small{display:block;font-weight:400;color:var(--muted)}tbody th{font-weight:550}.x{float:right;min-width:28px;min-height:28px}
.days li{display:grid;grid-template-columns:110px 1fr 90px 100px;gap:10px;padding:9px 0;border-bottom:1px solid var(--edge);font-size:12.5px;align-items:center}.days small{grid-column:1/-1;color:var(--muted);font-size:10px}.reg{padding-left:0;font-size:13px}.reg li{padding:6px 0;line-height:1.7}.reg a,.hint a{color:var(--blue);text-decoration:underline}
.alert-list li{border-bottom:1px solid var(--edge)}.alert-list li>button{display:block;width:100%;text-align:left;padding:10px 6px;min-height:44px}.alert-list small{display:block;font-size:11px;color:var(--muted)}.alert-list .unread b::before{content:'';display:inline-block;width:7px;height:7px;background:#a3123a;border-radius:50%;margin-right:7px}.alert-list.ended{opacity:.75}.block h3{margin-top:14px}
.wx-foot{margin-top:22px;font-size:11px;color:var(--muted);line-height:1.7}
@media(max-width:900px){.wx-body{grid-template-columns:300px minmax(0,1fr)}.summary{grid-template-columns:1fr}.summary dl{grid-template-columns:repeat(2,1fr)}.wx-header nav a:nth-child(n+4){display:none}.account span{display:none}}
@media(max-width:767px){.wx{position:static;min-height:100dvh;height:auto}.wx-header{height:auto;min-height:64px;flex-wrap:wrap;gap:6px 12px;padding:8px 14px;position:sticky;top:0}.identity{min-width:0}.identity small{display:none}.wx-header nav{order:3;width:100%;margin:0;height:40px;gap:16px;overflow-x:auto}.wx-header nav a{font-size:12px}.wx-header nav a:nth-child(n+4){display:flex}.wx-header .icon-btn{margin-left:auto}.wx-body{display:block}.wx-side{border-right:0;border-bottom:1px solid #cbd4db}.side-scroll{overflow:visible;padding:16px 14px}.side-map{height:220px}.wx-main{overflow:visible;padding:14px 14px 90px}.days li{grid-template-columns:90px 1fr 80px}.days li span:last-of-type{display:none}.tabs button{padding:10px 10px;font-size:12px}.account{padding:0 4px}}
</style>
