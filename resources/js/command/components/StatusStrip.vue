<script setup>
import { computed } from 'vue';
import { fmtClockWib } from '../lib/format';

const props = defineProps({
    status: String,
    error: String,
    via: String,
    stale: Boolean,
    lastOkAt: Date,
    lastCheckAt: Date,
    tilesError: Boolean,
    faultsState: String,
    sources: { type: Object, default: null },
});
const emit = defineEmits(['refresh']);

const text = computed(() => {
    if (props.status === 'loading') return 'Menghubungi BMKG InaTEWS…';
    if (props.status === 'error') return `Gagal mengambil data BMKG${props.lastOkAt ? `; menampilkan data terakhir (${fmtClockWib(props.lastOkAt)})` : ''}.`;
    if (props.stale) return `Data dari cache server, mungkin usang · ${fmtClockWib(props.lastOkAt)}`;

    return `BMKG InaTEWS tersambung${props.via === 'proksi' ? ' (lewat proksi)' : ''} · diperbarui ${fmtClockWib(props.lastOkAt)}`;
});
const EMSC = { open: 'tersambung (instan)', connecting: 'menyambung…', closed: 'terputus, mencoba lagi', unsupported: 'tidak didukung' };
const srcText = computed(() => {
    const x = props.sources;
    if (!x) return null;
    const bmkg = x.bmkg === 'ok' ? 'resmi' : x.bmkg === 'error' ? 'gagal' : 'memuat';
    const usgs = x.usgsAktif ? 'cadangan AKTIF' : x.usgs === 'error' ? 'cadangan gagal' : 'siaga';

    return `EMSC ${EMSC[x.emsc] ?? x.emsc} · BMKG ${bmkg} · USGS ${usgs}`;
});
const bad = computed(() => props.status === 'error' || props.stale);
</script>

<template>
    <div class="strip" :class="{ bad }" role="status">
        <span class="dot" aria-hidden="true"></span>
        <span class="t">{{ text }}</span>
        <button type="button" class="refresh" aria-label="Ambil data sekarang" @click="emit('refresh')">↻</button>
        <p v-if="srcText" class="sub src">{{ srcText }}</p>
        <p v-if="error && status !== 'error'" class="sub">{{ error }}</p>
        <p v-if="tilesError" class="sub">Peta dasar belum termuat; data gempa tetap tampil.</p>
        <p v-if="faultsState === 'error'" class="sub">Layer sesar aktif gagal dimuat.</p>
    </div>
</template>

<style scoped>
.strip { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 4px 8px; padding: 6px 10px; border: 1px solid #1f2937; background: #090d16; border-radius: 2px; font-size: 11px; color: #9ca3af; line-height: 1.4; }
.dot { width: 8px; height: 8px; background: #22c55e; border-radius: 1px; }
.strip.bad { border-color: #b45309; color: #fcd34d; }
.strip.bad .dot { background: #f59e0b; }
.refresh { min-width: 32px; min-height: 32px; background: transparent; border: 1px solid #1f2937; color: #e5e7eb; border-radius: 2px; cursor: pointer; font-size: 14px; }
.refresh:hover { background: #1f2937; }
.refresh:focus-visible { outline: 2px solid #38bdf8; outline-offset: 1px; }
.sub { grid-column: 1 / -1; margin: 0; color: #fcd34d; }
.sub.src { color: #9ca3af; }
@media (max-width: 767px) { .refresh { min-width: 44px; min-height: 44px; } }
</style>
