<script setup>
import '@fontsource/barlow/400.css';
import '@fontsource/barlow/600.css';
import '@fontsource/barlow/700.css';
import '@fontsource/barlow-condensed/600.css';
import '@fontsource/barlow-condensed/700.css';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BottomSheet from '../command/components/BottomSheet.vue';
import HomePanel from '../home/HomePanel.vue';
import MapFrame from '../home/MapFrame.vue';
import PortalHeader from '../home/PortalHeader.vue';
import { useHomeData } from '../home/useHomeData';

/**
 * Beranda berorientasi peta, sama dengan aplikasi Android: pita judul biru, peta Leaflet bergaya Google Maps memenuhi
 * area, dan panel data di kiri (desktop) atau lembar bawah yang bisa ditarik (ponsel).
 */
const props = defineProps({ week: Array, camps: Array, official: Object, announcements: Array });

const d = useHomeData({ initialEvents: props.week ?? [], initialCamps: props.camps ?? [] });
const mobile = ref(false);
const selectedId = ref(null);
const recenter = ref(0);
const sheet = ref(null);
const inset = ref(220);
let mq;
let settle;

const sorted = computed(() => [...d.events.value].sort((a, b) => new Date(b.properties.occurred_at) - new Date(a.properties.occurred_at)));
const latest = computed(() => sorted.value[0] ?? null);
const selected = computed(() => (selectedId.value ? sorted.value.find((f) => f.id === selectedId.value) ?? null : null));
const shown = computed(() => selected.value ?? latest.value);

// Gempa terakhir dipilih otomatis supaya peta langsung memusatkan dan menandai titiknya.
watch(latest, (l) => { if (!selectedId.value && l) selectedId.value = l.id; }, { immediate: true });

function select(id) {
    selectedId.value = id;
    // Di ponsel, bawa lembar ke Half agar rincian dan peta sama-sama terlihat.
    if (mobile.value) sheet.value?.snapTo(1);
}

// Inset bawah peta mengikuti lembar HANYA setelah berhenti (tidak tiap frame seret), supaya kamera tidak berulang terbang.
watch(() => sheet.value?.index, () => {
    clearTimeout(settle);
    settle = setTimeout(() => { inset.value = sheet.value?.height ?? 220; }, 340);
});
watch(mobile, (m) => { if (!m) inset.value = 0; else inset.value = 220; });

function onMq(e) { mobile.value = e.matches; }
onMounted(() => {
    mq = window.matchMedia('(max-width: 767px)');
    mobile.value = mq.matches;
    mq.addEventListener('change', onMq);
    document.body.classList.add('home-lock');
});
onBeforeUnmount(() => { clearTimeout(settle); mq?.removeEventListener('change', onMq); document.body.classList.remove('home-lock'); });
</script>

<template>
    <Head title="Peta gempa Indonesia" />
    <div class="home">
        <a class="skip" href="#panel-data">Lewati ke panel data</a>
        <PortalHeader active="peta" />

        <div class="stage">
            <aside v-if="!mobile" id="panel-data" class="side" aria-label="Panel data gempa" tabindex="-1">
                <p v-if="!selected" class="lead muted">{{ d.error.value ? 'Data gempa belum dapat dimuat.' : 'Memuat data gempa…' }}</p>
                <HomePanel :d="d" :selected="selected" @select="select" @clear="selectedId = latest?.id ?? null" />
            </aside>

            <div class="mapcol">
                <MapFrame :events="d.events.value" :camps="d.camps.value" :selected-id="selectedId" :bottom-inset="mobile ? inset : 0" :recenter-key="recenter" @select="select" />
                <button type="button" class="fab" aria-label="Pusatkan peta ke gempa terpilih" :disabled="!shown" @click="selectedId = selectedId ?? latest?.id; recenter++">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="1.8" /><path d="M12 1.5v5M12 17.5v5M1.5 12h5M17.5 12h5" stroke="currentColor" stroke-width="1.8" fill="none" /></svg>
                </button>
            </div>

            <BottomSheet v-if="mobile" id="panel-data" ref="sheet" class="lsheet" :enabled="mobile">
                <p v-if="!selected" class="lead muted">{{ d.error.value ? 'Data gempa belum dapat dimuat.' : 'Memuat data gempa…' }}</p>
                <HomePanel :d="d" :selected="selected" @select="select" @clear="selectedId = latest?.id ?? null" />
            </BottomSheet>
        </div>
    </div>
</template>

<style>
body.home-lock { overflow: hidden; }
.home { position: fixed; inset: 0; height: 100dvh; display: flex; flex-direction: column; background: #f2f5f9; font-family: 'Barlow', Arial, sans-serif; color: #1b2733; }
.home .skip { position: absolute; left: 8px; top: -60px; z-index: 2000; padding: 8px 12px; background: #fff; color: #111; }
.home .skip:focus { top: 60px; }
.home .stage { position: relative; flex: 1; min-height: 0; display: grid; grid-template-columns: 400px minmax(0, 1fr); }
.home .side { grid-column: 1; overflow-y: auto; overscroll-behavior: contain; background: #f2f5f9; border-right: 1px solid #d5dee8; scrollbar-width: thin; }
.home .side:focus { outline: none; }
.home .mapcol { grid-column: 2; position: relative; min-width: 0; }
.home .lead { background: #fff; padding: 12px 16px; border-bottom: 1px solid #d5dee8; }
.home .lead.muted { color: #566677; }
.home .kicker { margin: 0; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; color: #0b67b2; }
.home .lead-row { display: flex; align-items: center; gap: 12px; }
.home .lead-mag { font-family: 'Barlow Condensed', sans-serif; font-weight: 700; font-size: 52px; line-height: 1; }
.home .lead-loc { margin: 0; font-family: 'Barlow Condensed', sans-serif; font-weight: 700; font-size: 20px; line-height: 1.15; }
.home .lead .meta { margin: 2px 0 0; font-size: 13px; color: #566677; }
.home .fab { position: absolute; z-index: 1150; right: 10px; bottom: 36px; width: 44px; height: 44px; display: grid; place-items: center; background: #fff; color: #0b67b2; border: 0; border-radius: 8px; box-shadow: 0 1px 4px rgba(0, 0, 0, .3); cursor: pointer; }
.home .fab:disabled { opacity: .5; cursor: not-allowed; }
.home .fab:focus-visible { outline: 2px solid #0b67b2; outline-offset: 2px; }
.home .lsheet { --sheet-bg: #f2f5f9; --sheet-edge: #d5dee8; --sheet-grip: #c3cfdc; --sheet-radius: 12px; --sheet-pad: 0 0 24px; --sheet-gap: 0; }
@media (max-width: 767px) {
    .home .stage { display: block; }
    .home .mapcol { position: absolute; inset: 0; }
    .home .fab { bottom: 12px; transform: translate3d(0, calc(var(--sheet-height, 220px) * -1), 0); will-change: transform; }
}
@media (min-width: 768px) and (max-width: 1000px) { .home .stage { grid-template-columns: 340px minmax(0, 1fr); } }
</style>
