<script setup>
import '@fontsource-variable/jetbrains-mono';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BottomSheet from '../command/components/BottomSheet.vue';
import CommandMap from '../command/components/CommandMap.vue';
import CommandPanel from '../command/components/CommandPanel.vue';
import ControlBar from '../command/components/ControlBar.vue';
import TickerBar from '../command/components/TickerBar.vue';
import { useAlertSystem } from '../command/composables/useAlertSystem';
import { useBmkgFeed } from '../command/composables/useBmkgFeed';
import { useFeltReports } from '../command/composables/useFeltReports';
import { useSeismicIngest } from '../command/composables/useSeismicIngest';
import { useUserLocation } from '../command/composables/useUserLocation';
import { useWaveSimulation } from '../command/composables/useWaveSimulation';
import { bearingDeg, compass8, haversineKm } from '../command/lib/geo';
import { estimateLocalMmi } from '../command/lib/mmi';
import { proximityBadge } from '../command/lib/seismic';

/**
 * Pusat komando gempa (Map-First): peta penuh layar, ticker berita di atas, panel data di kiri (desktop) atau bottom
 * sheet (ponsel). Data dari BMKG InaTEWS, dipoll tiap 60 detik.
 */
const TICKER_H = 32;
const PANEL_W = 360;

const mobile = ref(false);
const selectedKey = ref(null);
const toast = ref(null);
const tilesError = ref(false);
const faultsState = ref('idle');
const map = ref(null);
const sheet = ref(null);
let mq;
let toastTimer;

const BLACKOUT_KEY = 'igi:komando:blackout';
const blackout = ref(false);
try { blackout.value = localStorage.getItem(BLACKOUT_KEY) === '1'; } catch { /* tanpa penyimpanan */ }
function toggleBlackout() {
    blackout.value = !blackout.value;
    try { localStorage.setItem(BLACKOUT_KEY, blackout.value ? '1' : '0'); } catch { /* abaikan */ }
}

const alert = useAlertSystem();

function announce(q, text) {
    // Gempa baru otomatis dipilih dan diumumkan (role=status); pengguna bisa kembali ke pilihan sebelumnya lewat daftar.
    selectedKey.value = q.key;
    toast.value = { q, text };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value = null; }, 12_000);
}

const feed = useBmkgFeed({
    lite: blackout,
    onNewQuake: (q) => {
        announce(q, `GEMPA BARU M${q.mag.toFixed(1)}`);
        alert.validated(q);
    },
});

// EMSC (instan, menunggu validasi), BMKG (resmi), USGS (cadangan bila BMKG gagal).
const ingest = useSeismicIngest(feed, {
    onDetected: (q) => {
        announce(q, `TERDETEKSI M${q.mag.toFixed(1)} · MENUNGGU VALIDASI BMKG`);
        alert.detected(q);
    },
    onValidated: (official, _via, emscKey) => {
        // Pilihan pengguna ikut pindah dari kejadian EMSC yang menghilang dari daftar ke pasangan resminya.
        if (selectedKey.value === emscKey) selectedKey.value = official.key;
        toast.value = { q: official, text: `TERVALIDASI BMKG M${official.mag.toFixed(1)}` };
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toast.value = null; }, 12_000);
        alert.validated(official, 'emsc');
    },
});

const quake = computed(() => ingest.quakes.value.find((q) => q.key === selectedKey.value) ?? ingest.latest.value);
const isLatest = computed(() => !quake.value || quake.value.key === ingest.latest.value?.key);

const loc = useUserLocation();
const telemetry = computed(() => {
    const q = quake.value;
    const p = loc.position.value;
    if (!q || !p) return null;
    const distanceKm = haversineKm(p.lat, p.lng, q.lat, q.lng);
    const bearing = bearingDeg(p.lat, p.lng, q.lat, q.lng); // dari pengguna menuju episentrum
    const depth = q.depthKm ?? 10;

    return {
        distanceKm,
        hypoKm: Math.sqrt(distanceKm ** 2 + depth ** 2),
        bearing,
        compass: compass8(bearing),
        badge: proximityBadge(distanceKm, q.mag),
        mmi: estimateLocalMmi({ magnitude: q.mag, depthKm: q.depthKm, distanceKm }),
    };
});

const sim = useWaveSimulation(quake, computed(() => telemetry.value?.distanceKm ?? null), computed(() => !blackout.value));
const felt = useFeltReports({ paused: blackout });

// Saat lokasi pertama kali didapat pada simulasi (bukan real-time), ulangi dari awal agar hitung mundur ETA bermakna.
watch(loc.position, (p, prev) => { if (p && !prev && !sim.live.value && !blackout.value) sim.restartSimulation(); });

const panelProps = computed(() => ({ sources: ingest.sources.value, blackout: blackout.value, felt, hasLocation: Boolean(loc.position.value) }));

/**
 * Area peta yang tertutup UI, dipakai untuk menghitung kamera: ticker di atas; panel kiri di desktop; bottom sheet
 * (tinggi saat ini) di ponsel.
 */
function getInsets() {
    return mobile.value
        ? { top: TICKER_H, bottom: sheet.value?.height ?? 220, left: 0, right: 0 }
        : { top: TICKER_H, bottom: 0, left: PANEL_W + 12, right: 0 };
}

function select(key) {
    selectedKey.value = key;
    // Di ponsel, naikkan sheet dari Peek agar rincian yang dipilih terlihat.
    if (mobile.value && sheet.value?.index === 0) sheet.value.snapTo(1);
}

function onMq(e) { mobile.value = e.matches; }
onMounted(() => {
    document.body.classList.add('cmd-lock');
    mq = window.matchMedia('(max-width: 767px)');
    mobile.value = mq.matches;
    mq.addEventListener('change', onMq);
});
onBeforeUnmount(() => { document.body.classList.remove('cmd-lock'); mq?.removeEventListener('change', onMq); clearTimeout(toastTimer); });
</script>

<template>
    <Head title="Pusat komando gempa" />
    <div class="cmd" :class="{ blackout }">
        <a class="skip" href="#panel-data">Lewati ke panel data</a>
        <TickerBar :quakes="ingest.quakes.value" :status="feed.status.value" :active-key="quake?.key" @select="select" />

        <CommandMap
            ref="map"
            :quake="quake"
            :quakes="ingest.quakes.value"
            :reports="felt.clusters.value"
            :blackout="blackout"
            :user-pos="loc.position.value"
            :rp="sim.rp.value"
            :rs="sim.rs.value"
            :get-insets="getInsets"
            @select="select"
            @tiles-error="tilesError = true"
            @tiles-ok="tilesError = false"
            @faults-state="(s) => (faultsState = s)"
        />

        <div v-if="toast" class="toast" role="status">
            <b>{{ toast.text }}</b> {{ toast.q.region }}
            <button type="button" aria-label="Tutup pemberitahuan" @click="toast = null">×</button>
        </div>

        <aside v-if="!mobile" id="panel-data" class="side" aria-label="Panel data gempa" tabindex="-1">
            <header class="brand">
                <Link href="/" class="back" aria-label="Kembali ke beranda IGI">← IGI</Link>
                <h1>Pusat Komando</h1>
                <nav aria-label="Halaman lain"><Link href="/cuaca">Cuaca</Link><Link href="/berita">Berita</Link></nav>
            </header>
            <ControlBar :sound-on="alert.enabled.value" :speaking="alert.speaking.value" :blackout="blackout" @toggle-sound="alert.toggle()" @test-sound="alert.test()" @toggle-blackout="toggleBlackout" />
            <CommandPanel
                :quake="quake" :is-latest="isLatest" :quakes="ingest.quakes.value" :feed="feed" v-bind="panelProps" @report="(lv) => felt.report(lv, loc.position.value, quake?.key)" :sim="sim"
                :telemetry="telemetry" :location-status="loc.status.value" :tiles-error="tilesError" :faults-state="faultsState"
                @select="select" @back-to-latest="selectedKey = null" @request-location="loc.request()" @clear-location="loc.clear()" @refresh="feed.refresh()"
            />
        </aside>

        <BottomSheet v-else ref="sheet" :enabled="mobile">
            <div id="panel-data" tabindex="-1">
                <header class="brand mini"><Link href="/" class="back" aria-label="Kembali ke beranda IGI">← IGI</Link><h1>Pusat Komando</h1></header>
                <ControlBar :sound-on="alert.enabled.value" :speaking="alert.speaking.value" :blackout="blackout" @toggle-sound="alert.toggle()" @test-sound="alert.test()" @toggle-blackout="toggleBlackout" />
            </div>
            <CommandPanel
                :quake="quake" :is-latest="isLatest" :quakes="ingest.quakes.value" :feed="feed" v-bind="panelProps" @report="(lv) => felt.report(lv, loc.position.value, quake?.key)" :sim="sim"
                :telemetry="telemetry" :location-status="loc.status.value" :tiles-error="tilesError" :faults-state="faultsState"
                @select="select" @back-to-latest="selectedKey = null" @request-location="loc.request()" @clear-location="loc.clear()" @refresh="feed.refresh()"
            />
        </BottomSheet>

        <div v-if="blackout" class="dim" aria-hidden="true"></div>

        <!-- Tombol recenter. Posisinya setara bottom: calc(var(--sheet-height) + 12px), diterapkan dengan transform supaya 60 FPS tanpa reflow. -->
        <button type="button" class="fab" aria-label="Pusatkan peta ke episentrum" :disabled="!quake" @click="map?.recenter()">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="6" fill="none" stroke="currentColor" stroke-width="1.6" /><path d="M12 1v6M12 17v6M1 12h6M17 12h6" stroke="currentColor" stroke-width="1.6" fill="none" /></svg>
        </button>
    </div>
</template>

<style>
/* Kunci gulir hanya selama halaman ini terbuka (kelas dipasang di onMounted, dicabut di onBeforeUnmount). */
body.cmd-lock { overflow: hidden; }
.cmd { position: fixed; inset: 0; height: 100dvh; background: #090d16; color: #e5e7eb; font-family: 'JetBrains Mono Variable', ui-monospace, 'SFMono-Regular', Menlo, monospace; font-size: 13px; line-height: 1.5; }
.cmd *, .cmd *::before, .cmd *::after { box-sizing: border-box; }
.cmd .skip { position: absolute; left: 8px; top: -60px; z-index: 2000; padding: 8px 12px; background: #fff; color: #111; }
.cmd .skip:focus { top: 36px; }

.cmd .side { position: absolute; z-index: 1000; top: 32px; bottom: 0; left: 0; width: 360px; overflow-y: auto; overscroll-behavior: contain; padding: 12px; display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; align-content: start; background: #090d16; border-right: 1px solid #1f2937; scrollbar-width: thin; scrollbar-color: #374151 transparent; }
.cmd .side:focus { outline: none; }
.cmd .brand { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cmd .brand h1 { margin: 0; flex: 1; font-size: 13px; letter-spacing: .12em; text-transform: uppercase; font-weight: 700; color: #f9fafb; }
.cmd .brand .back, .cmd .brand nav a { color: #38bdf8; text-decoration: none; font-size: 12px; min-height: 28px; display: inline-flex; align-items: center; }
.cmd .brand nav { display: flex; gap: 12px; }
.cmd .brand a:hover { text-decoration: underline; }
.cmd .brand a:focus-visible { outline: 2px solid #38bdf8; outline-offset: 2px; }
.cmd .brand.mini { padding-top: 2px; }

.cmd .toast { position: absolute; z-index: 1300; top: 40px; left: 50%; transform: translateX(-50%); max-width: calc(100% - 24px); display: flex; gap: 10px; align-items: center; padding: 8px 12px; background: #450a0a; border: 1px solid #ef4444; border-radius: 2px; font-size: 12px; }
.cmd .toast button { background: none; border: 0; color: #fecaca; font-size: 18px; cursor: pointer; min-width: 32px; min-height: 32px; }

.cmd .fab { position: absolute; z-index: 1150; right: 12px; bottom: 36px; width: 44px; height: 44px; display: grid; place-items: center; background: #111827; color: #e5e7eb; border: 1px solid #374151; border-radius: 2px; cursor: pointer; transform: translate3d(0, calc(var(--sheet-height, 0px) * -1), 0); will-change: transform; }
.cmd .fab:hover:not(:disabled) { background: #1f2937; border-color: #38bdf8; color: #38bdf8; }
.cmd .fab:disabled { opacity: .5; cursor: not-allowed; }
.cmd .fab:focus-visible { outline: 2px solid #38bdf8; outline-offset: 2px; }
@media (max-width: 767px) { .cmd .fab { bottom: 12px; } }

/* Mode Blackout: OLED true black #000, tanpa animasi dan transisi, antarmuka diredupkan lewat lapisan hitam. */
.cmd.blackout, .cmd.blackout .side { background: #000; }
.cmd.blackout .side { border-right-color: #262626; }
.cmd.blackout :is(section, .strip, .ctl button, .fab, .toast) { background: #000 !important; border-color: #262626 !important; }
.cmd.blackout *, .cmd.blackout *::before, .cmd.blackout *::after { animation: none !important; transition: none !important; }
.cmd .dim { position: fixed; inset: 0; z-index: 1600; background: rgba(0, 0, 0, .4); pointer-events: none; }
@media (prefers-reduced-motion: reduce) { .cmd .fab { will-change: auto; } }
</style>
