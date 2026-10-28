<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { MAG, TIPS, WAKTU, lalu, mag1, magnitudeColor, num, waktuWib } from './format';

// Isi panel beranda (panel kiri di desktop, bottom sheet di ponsel): sama dengan lembar bawah aplikasi Android.
const props = defineProps({ d: { type: Object, required: true }, selected: { type: Object, default: null } });
const emit = defineEmits(['select', 'clear']);

const showAll = ref(false);
const day = ref(null);
const tip = ref(0);
const notice = ref('');
const wx = ref({ state: 'idle', data: null, place: null, message: '' });

const sorted = computed(() => [...props.d.events.value].sort((a, b) => new Date(b.properties.occurred_at) - new Date(a.properties.occurred_at)));
const list = computed(() => (showAll.value ? sorted.value : sorted.value.slice(0, 8)));
const sp = computed(() => props.selected?.properties ?? null);

const dayKey = (iso) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));
const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const perDay = computed(() => {
    const out = [];
    for (let i = 6; i >= 0; i--) {
        const key = dayKey(new Date(Date.now() - i * 86_400_000).toISOString());
        out.push({ key, label: HARI[new Date(`${key}T12:00:00Z`).getUTCDay()], n: 0, max: 0 });
    }
    for (const f of props.d.events.value) {
        const x = out.find((o) => o.key === dayKey(f.properties.occurred_at));
        if (x) { x.n += 1; x.max = Math.max(x.max, f.properties.magnitude ?? 0); }
    }

    return out;
});
const maxN = computed(() => Math.max(1, ...perDay.value.map((x) => x.n)));

async function loadWeather(place) {
    wx.value = { state: 'loading', data: null, place, message: '' };
    const q = place.adm4 ? `adm4=${place.adm4}` : `lat=${Number(place.latitude).toFixed(2)}&lon=${Number(place.longitude).toFixed(2)}&name=${encodeURIComponent((place.name ?? '').slice(0, 100))}`;
    try {
        const r = await fetch(`/api/v1/weather?${q}`, { headers: { Accept: 'application/json' } });
        const body = await r.json();
        if (!r.ok || body.status === 'error') throw new Error(body?.error?.message ?? `HTTP ${r.status}`);
        wx.value = { state: 'ok', data: body, place, message: '' };
    } catch (e) {
        wx.value = { state: 'error', data: null, place, message: e.message };
    }
}

function useDevice() {
    if (!('geolocation' in navigator)) { wx.value = { state: 'error', data: null, place: null, message: 'Peramban ini tidak mendukung lokasi. Cari tempat di halaman Cuaca.' }; return; }
    wx.value = { state: 'loading', data: null, place: null, message: '' };
    navigator.geolocation.getCurrentPosition(
        // Dibulatkan ±1 km dan tidak disimpan.
        (p) => loadWeather({ name: 'Lokasi Anda (perkiraan)', latitude: +p.coords.latitude.toFixed(2), longitude: +p.coords.longitude.toFixed(2) }),
        (e) => { wx.value = { state: 'error', data: null, place: null, message: e.code === 1 ? 'Izin lokasi ditolak. Anda tetap bisa mencari tempat di halaman Cuaca.' : 'Lokasi perangkat tidak dapat ditentukan.' }; },
        { timeout: 12_000, maximumAge: 120_000 },
    );
}

onMounted(() => {
    try {
        const fav = JSON.parse(localStorage.getItem('igi:cuaca:favorit') ?? '[]')[0];
        if (fav) loadWeather(fav);
    } catch { /* tanpa favorit */ }
});

async function share() {
    const f = props.selected;
    if (!f) return;
    const url = new URL(`/berita/${f.id}`, location.origin).href;
    const text = `Gempa M ${mag1(sp.value.magnitude)} ${sp.value.location} (${waktuWib(sp.value.occurred_at)}). Sumber ${String(sp.value.source).toUpperCase()}.`;
    try {
        if (navigator.share) await navigator.share({ title: 'Gempa terkini', text, url });
        else { await navigator.clipboard.writeText(`${text} ${url}`); notice.value = 'Tautan disalin.'; }
    } catch { /* dibatalkan */ }
}

const csvHref = computed(() => `/api/v1/disasters-export?hours=${props.d.hours.value}${props.d.minMag.value > 0 ? `&min_magnitude=${props.d.minMag.value}` : ''}`);
const wxc = computed(() => wx.value.data?.current ?? null);
</script>

<template>
    <div class="hp">
        <p v-if="d.error.value" class="notice" role="alert">{{ d.error.value }} Data sebelumnya tetap ditampilkan. <button type="button" class="link" @click="d.reload()">Coba lagi</button></p>

        <section v-if="selected && sp" class="block selected" aria-labelledby="sel-h">
            <div class="row"><h2 id="sel-h" class="kicker accent">Gempa terpilih</h2><button type="button" class="link" @click="emit('clear')">Tutup</button></div>
            <div class="sel-top"><span class="sel-mag" :style="{ color: magnitudeColor(sp.magnitude) }">{{ mag1(sp.magnitude) }}</span><div><p class="sel-loc">{{ sp.location }}</p><p class="meta">{{ waktuWib(sp.occurred_at) }}, {{ lalu(sp.occurred_at) }}</p></div></div>
            <dl class="facts"><div><dt>Kedalaman</dt><dd>{{ sp.depth_km != null ? `${Math.round(sp.depth_km)} km` : '-' }}</dd></div><div><dt>Koordinat</dt><dd>{{ selected.geometry.coordinates[1].toFixed(2) }}, {{ selected.geometry.coordinates[0].toFixed(2) }}</dd></div><div><dt>Sumber</dt><dd>{{ String(sp.source).toUpperCase() }}</dd></div></dl>
            <p class="body">{{ sp.tsunami_potential ?? 'Informasi tsunami tidak tersedia pada catatan ini. Periksa pembaruan resmi BMKG.' }}</p>
            <div class="actions"><button type="button" class="ghost" @click="share">Bagikan</button><Link href="/komando" class="solid">Simulasi gelombang</Link><Link :href="`/berita/${selected.id}`" class="ghost">Halaman kejadian</Link></div>
            <p v-if="notice" class="meta" role="status">{{ notice }}</p>
        </section>

        <section class="block" aria-labelledby="flt-h">
            <h2 id="flt-h" class="kicker">Saringan peta dan daftar</h2>
            <div class="chips" role="group" aria-label="Rentang waktu"><button v-for="o in WAKTU" :key="o.v" type="button" class="chip" :aria-pressed="d.hours.value === o.v" @click="d.setHours(o.v)">{{ o.l }}</button></div>
            <div class="chips" role="group" aria-label="Magnitudo minimum"><button v-for="o in MAG" :key="o.v" type="button" class="chip" :aria-pressed="d.minMag.value === o.v" @click="d.setMinMag(o.v)">{{ o.l }}</button></div>
            <p class="meta">{{ d.loading.value ? 'Memuat…' : `${d.events.value.length} gempa tercatat sesuai saringan. Data dimuat ${d.loadedAt.value ? lalu(d.loadedAt.value) : '-'}.` }}</p>
        </section>

        <section v-if="d.stats.value" class="block" aria-label="Ringkasan angka">
            <div class="panel figures">
                <div v-for="(f, i) in [['24 jam', d.stats.value.quakes_24h], ['7 hari', d.stats.value.quakes_7d], ['M 5+', d.stats.value.strong_7d], ['Posko', d.stats.value.camps_open]]" :key="f[0]" class="fig" :class="{ rule: i > 0 }"><b>{{ Number(f[1]).toLocaleString('id-ID') }}</b><span>{{ f[0] }}</span></div>
            </div>
            <p class="meta">Gempa tercatat. M 5+ dihitung 7 hari terakhir; {{ Number(d.stats.value.occupants ?? 0).toLocaleString('id-ID') }} orang tercatat di posko aktif.</p>
        </section>

        <section class="block" aria-labelledby="alr-h">
            <div class="panel" :class="{ warn: d.alerts.value?.ok && d.alerts.value.count > 0 }">
                <h2 id="alr-h" class="kicker" :class="{ accent: d.alerts.value?.ok && d.alerts.value.count > 0 }">Peringatan dini cuaca</h2>
                <p v-if="!d.alerts.value" class="meta">Memeriksa…</p>
                <p v-else-if="!d.alerts.value.ok" class="body">Belum dapat dicek. Ini bukan berarti tidak ada peringatan.</p>
                <p v-else-if="d.alerts.value.count === 0" class="body">Tidak ada peringatan dini cuaca aktif pada pemeriksaan terakhir.</p>
                <template v-else><p class="alert-num">{{ d.alerts.value.count }} peringatan BMKG aktif</p><p v-if="d.alerts.value.first" class="meta">Terbaru: {{ d.alerts.value.first.title }}</p><Link href="/cuaca" class="link">Lihat di halaman Cuaca</Link></template>
            </div>
        </section>

        <section class="block" aria-labelledby="wx-h">
            <div class="panel">
                <h2 id="wx-h" class="kicker">Cuaca</h2>
                <template v-if="wx.state === 'idle'"><p class="body">Lihat cuaca di tempat Anda. Lokasi hanya dipakai saat tombol ditekan dan tidak disimpan.</p><button type="button" class="link" @click="useDevice">Pakai lokasi saya</button><Link href="/cuaca" class="link">Cari tempat lain</Link></template>
                <p v-else-if="wx.state === 'loading'" class="meta">Memuat cuaca…</p>
                <template v-else-if="wx.state === 'error'"><p class="body danger">{{ wx.message }}</p><button type="button" class="link" @click="useDevice">Coba lagi</button></template>
                <Link v-else-if="wxc" href="/cuaca" class="wx"><span class="temp">{{ num(wxc.temperature, '°C', 0) }}</span><span class="cond">{{ wxc.condition ?? '-' }}</span><span class="meta">{{ wx.place?.name }} · hujan {{ num(wxc.rain_probability, '%') }} · angin {{ num(wxc.wind_speed, 'km/jam', 0) }}</span></Link>
            </div>
        </section>

        <section class="block" aria-labelledby="lst-h">
            <div class="panel">
                <h2 id="lst-h" class="kicker">Daftar gempa</h2>
                <p v-if="!sorted.length" class="body">Tidak ada gempa pada saringan ini. Tidak adanya catatan bukan penetapan bahwa suatu wilayah aman.</p>
                <ul class="events"><li v-for="f in list" :key="f.id"><button type="button" :class="{ on: selected?.id === f.id }" :aria-current="selected?.id === f.id ? 'true' : undefined" @click="emit('select', f.id)"><i :style="{ background: magnitudeColor(f.properties.magnitude) }"></i><b>{{ mag1(f.properties.magnitude) }}</b><span><em>{{ f.properties.location }}</em><small>{{ lalu(f.properties.occurred_at) }} · {{ f.properties.depth_km != null ? `${Math.round(f.properties.depth_km)} km` : 'kedalaman -' }} · {{ String(f.properties.source).toUpperCase() }}</small></span></button></li></ul>
                <button v-if="sorted.length > 8" type="button" class="link" @click="showAll = !showAll">{{ showAll ? 'Tampilkan lebih sedikit' : `Tampilkan semua (${sorted.length})` }}</button>
                <a class="link" :href="csvHref" download>Unduh CSV sesuai saringan</a>
            </div>
        </section>

        <section class="block" aria-labelledby="cht-h">
            <div class="panel">
                <h2 id="cht-h" class="kicker">Berapa gempa tercatat per hari?</h2>
                <div class="chart"><button v-for="(x, i) in perDay" :key="x.key" type="button" :aria-label="`${x.label}: ${x.n} gempa`" @click="day = day === i ? null : i"><span class="n" :class="{ on: day === i }">{{ x.n }}</span><i :style="{ height: `${Math.max(3, (x.n / maxN) * 70)}px`, background: day === i ? '#0B67B2' : magnitudeColor(x.max || 3) }"></i><span class="l" :class="{ on: day === i }">{{ x.label }}</span></button></div>
                <p class="meta">{{ day == null ? 'Klik satu hari untuk rincian. Waktu WIB; sesuai saringan di atas.' : `${perDay[day].n} gempa tercatat${perDay[day].n ? `, terkuat M ${mag1(perDay[day].max)}` : ''} pada ${perDay[day].key}.` }}</p>
            </div>
        </section>

        <section class="block" aria-labelledby="mnu-h">
            <div class="panel">
                <h2 id="mnu-h" class="kicker">Menu</h2>
                <ul class="menu">
                    <li><Link href="/komando"><b>Pusat komando</b><small>Gelombang P dan S, jarak ke Anda</small></Link></li>
                    <li><Link href="/cuaca"><b>Cuaca wilayah</b><small>Cari tempat, favorit, bandingkan</small></Link></li>
                    <li><Link href="/berita"><b>Peringatan dan berita</b><small>BMKG dan BNPB</small></Link></li>
                    <li><Link href="/panduan"><b>Panduan siaga</b><small>Langkah menghadapi gempa</small></Link></li>
                    <li><Link href="/masuk"><b>Area petugas</b><small>Masuk dan lihat posko Anda</small></Link></li>
                </ul>
            </div>
        </section>

        <section class="block"><button type="button" class="tip" @click="tip = (tip + 1) % TIPS.length"><span class="kicker accent">Siaga</span><b>{{ TIPS[tip].title }}</b><span>{{ TIPS[tip].body }}</span><small>Klik untuk tips berikutnya ({{ tip + 1 }} dari {{ TIPS.length }})</small></button></section>

        <section v-if="d.news.value.length" class="block" aria-labelledby="nws-h">
            <div class="panel">
                <div class="row"><h2 id="nws-h" class="kicker">Kabar dari lembaga</h2><Link href="/berita" class="link">Semua</Link></div>
                <ul class="news"><li v-for="n in d.news.value" :key="n.id"><a :href="n.url" target="_blank" rel="noopener noreferrer"><small>{{ n.source_label }}, {{ lalu(n.published_at) }}</small><b>{{ n.title }}</b></a></li></ul>
            </div>
        </section>

        <section class="block" aria-labelledby="tel-h">
            <h2 id="tel-h" class="kicker">Nomor penting</h2>
            <div class="phones"><a v-for="p in [['Darurat', '112'], ['Basarnas', '115'], ['BNPB', '117']]" :key="p[1]" :href="`tel:${p[1]}`"><b>{{ p[1] }}</b><span>{{ p[0] }}</span></a></div>
            <p class="foot">IGI bukan pengganti peringatan resmi. Untuk arahan evakuasi ikuti BMKG, BPBD, dan BNPB. Sumber: BMKG, USGS, BNPB, Open-Meteo. <Link href="/tentang" class="link">Tentang data</Link></p>
        </section>
    </div>
</template>

<style scoped>
.hp { display: grid; gap: 0; color: #1b2733; font-family: 'Barlow', Arial, sans-serif; font-size: 15px; line-height: 1.5; }
.block { padding: 0 16px; margin-top: 14px; }
.panel { background: #fff; border: 1px solid #d5dee8; border-radius: 8px; padding: 14px; }
.panel.warn { border-color: #e65100; }
.kicker { margin: 0 0 4px; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; color: #566677; }
.kicker.accent { color: #0b67b2; }
.meta { margin: 2px 0 0; font-size: 13px; color: #566677; }
.body { margin: 4px 0; font-size: 16px; line-height: 1.5; }
.danger { color: #c62828; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.link { display: inline-flex; align-items: center; min-height: 44px; background: none; border: 0; padding: 0; margin-right: 14px; font: inherit; font-weight: 700; color: #0b67b2; cursor: pointer; text-decoration: none; }
.link:hover { text-decoration: underline; }
.hp :is(button, a):focus-visible { outline: 2px solid #0b67b2; outline-offset: 2px; }
.notice { margin: 12px 16px 0; padding: 10px 12px; background: #fff3e0; border: 1px solid #e65100; border-radius: 8px; font-size: 14px; }
.selected { border-bottom: 1px solid #d5dee8; padding-bottom: 14px; }
.sel-top { display: flex; align-items: center; gap: 12px; margin-top: 4px; }
.sel-mag { font-family: 'Barlow Condensed', sans-serif; font-weight: 700; font-size: 64px; line-height: 1; }
.sel-loc { margin: 0; font-family: 'Barlow Condensed', sans-serif; font-weight: 700; font-size: 22px; line-height: 1.15; }
.facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 10px 0; }
.facts dt { font-size: 12px; color: #566677; }
.facts dd { margin: 0; font-weight: 600; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.solid, .ghost { display: inline-flex; align-items: center; min-height: 44px; padding: 0 14px; border-radius: 8px; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
.solid { background: #0b67b2; color: #fff; border: 1px solid #0b67b2; }
.ghost { background: #fff; color: #0b67b2; border: 1px solid #d5dee8; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
.chip { min-height: 40px; padding: 0 14px; border-radius: 8px; border: 1px solid #d5dee8; background: #fff; color: #566677; font: inherit; cursor: pointer; }
.chip[aria-pressed='true'] { background: #e1effb; border-color: #0b67b2; color: #0b67b2; font-weight: 700; }
.figures { display: grid; grid-template-columns: repeat(4, 1fr); padding: 10px 4px; }
.fig { padding: 0 10px; }
.fig.rule { border-left: 1px solid #d5dee8; }
.fig b { display: block; font-family: 'Barlow Condensed', sans-serif; font-size: 34px; line-height: 1.1; font-variant-numeric: tabular-nums; }
.fig span { font-size: 13px; color: #566677; }
.alert-num { margin: 2px 0; font-family: 'Barlow Condensed', sans-serif; font-weight: 700; font-size: 24px; color: #e65100; }
.wx { display: grid; text-decoration: none; color: inherit; }
.temp { font-family: 'Barlow Condensed', sans-serif; font-weight: 700; font-size: 46px; line-height: 1.1; }
.cond { font-size: 17px; font-weight: 600; }
.events { list-style: none; margin: 0; padding: 0; }
.events li + li { border-top: 1px solid #e3e9f0; }
.events button { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 56px; padding: 8px 0; background: transparent; border: 0; text-align: left; font: inherit; cursor: pointer; }
.events button:hover, .events button.on { background: #e1effb; }
.events i { align-self: stretch; width: 4px; border-radius: 2px; flex: none; }
.events b { min-width: 46px; font-family: 'Barlow Condensed', sans-serif; font-size: 28px; font-variant-numeric: tabular-nums; }
.events span { display: grid; min-width: 0; }
.events em { font-style: normal; font-weight: 600; font-size: 15px; }
.events small, .news small { font-size: 13px; color: #566677; }
.chart { display: flex; justify-content: space-between; align-items: flex-end; height: 120px; padding-top: 10px; }
.chart button { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 4px; min-width: 38px; height: 120px; background: none; border: 0; font: inherit; cursor: pointer; }
.chart i { display: block; width: 22px; border-radius: 4px; }
.chart .n, .chart .l { font-size: 13px; color: #566677; }
.chart .on { color: #0b67b2; font-weight: 700; }
.menu { list-style: none; margin: 0; padding: 0; }
.menu li + li { border-top: 1px solid #e3e9f0; }
.menu a { display: grid; padding: 10px 0; min-height: 62px; text-decoration: none; color: inherit; align-content: center; }
.menu b { font-family: 'Barlow Condensed', sans-serif; font-size: 22px; }
.menu small { color: #566677; font-size: 13px; }
.menu a:hover b { color: #0b67b2; }
.tip { display: grid; gap: 6px; width: 100%; text-align: left; padding: 14px; background: #e1effb; border: 1px solid #0b67b2; border-radius: 8px; font: inherit; cursor: pointer; }
.tip b { font-family: 'Barlow Condensed', sans-serif; font-size: 22px; line-height: 1.15; }
.tip small { color: #566677; }
.news { list-style: none; margin: 0; padding: 0; }
.news li + li { border-top: 1px solid #e3e9f0; }
.news a { display: grid; gap: 2px; padding: 10px 0; text-decoration: none; color: inherit; }
.news b { font-size: 16px; line-height: 1.4; }
.news a:hover b { color: #0b67b2; }
.phones { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 6px; }
.phones a { display: grid; place-items: center; min-height: 78px; background: #fff; border: 1px solid #d5dee8; border-radius: 8px; text-decoration: none; color: inherit; }
.phones b { font-family: 'Barlow Condensed', sans-serif; font-size: 34px; color: #c62828; line-height: 1.1; }
.phones span { font-size: 13px; color: #566677; }
.foot { margin: 12px 0 28px; font-size: 13px; color: #566677; line-height: 1.5; }
</style>
