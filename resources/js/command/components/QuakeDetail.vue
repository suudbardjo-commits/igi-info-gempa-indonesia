<script setup>
import { computed, ref, watch } from 'vue';
import { fmtAgo, fmtCoord, fmtWib } from '../lib/format';
import { magnitudeColor } from '../lib/seismic';

const props = defineProps({ quake: { type: Object, default: null }, isLatest: { type: Boolean, default: true }, blackout: { type: Boolean, default: false } });
const emit = defineEmits(['back-to-latest']);

const VAL = {
    pending: 'PENDING BMKG VALIDATION: terdeteksi EMSC, belum dikonfirmasi BMKG',
    fallback: 'DATA CADANGAN USGS: BMKG tidak dapat dihubungi, bukan data resmi Indonesia',
    official: 'DATA RESMI BMKG',
};
const validationText = computed(() => {
    const q = props.quake;
    // EMSC mencakup tepi wilayah (Filipina, Timor, PNG) yang tidak dilaporkan BMKG: setelah 15 menit tanpa pasangan, jelaskan.
    if (q?.validation === 'pending' && Date.now() - q.time > 15 * 60_000) return 'BELUM DIKONFIRMASI BMKG (lebih dari 15 menit): mungkin di luar wilayah pantauan BMKG';

    return VAL[q?.validation ?? 'official'];
});
const shakeFailed = ref(false);
const shakeLoaded = ref(false);
watch(() => props.quake?.key, () => { shakeFailed.value = false; shakeLoaded.value = false; });
</script>

<template>
    <section class="detail" aria-labelledby="det-h">
        <p v-if="!quake" class="empty">Belum ada gempa dipilih.</p>
        <template v-else>
            <header>
                <h3 id="det-h">{{ isLatest ? 'Gempa terbaru' : 'Gempa terpilih' }}</h3>
                <button v-if="!isLatest" type="button" class="link" @click="emit('back-to-latest')">← ke gempa terbaru</button>
            </header>
            <p class="val" :class="quake.validation ?? 'official'" role="status">{{ validationText }}</p>
            <div class="top">
                <div class="mag" :style="{ color: magnitudeColor(quake.mag), borderColor: magnitudeColor(quake.mag) }" :aria-label="`Magnitudo ${quake.mag}`">{{ quake.mag.toFixed(1) }}<small>M</small></div>
                <div class="who">
                    <p class="region">{{ quake.region }}</p>
                    <p class="time">{{ fmtWib(quake.time) }} · {{ fmtAgo(quake.time) }}</p>
                </div>
            </div>
            <dl class="grid">
                <div><dt>Kedalaman</dt><dd>{{ quake.depthKm != null ? `${quake.depthKm} km` : '-' }}</dd></div>
                <div><dt>Koordinat</dt><dd>{{ fmtCoord(quake.lat, quake.lng) }}</dd></div>
                <div class="wide"><dt>Potensi tsunami</dt><dd :class="{ warn: quake.tsunami && /berpotensi/i.test(quake.tsunami) && !/tidak/i.test(quake.tsunami) }">{{ quake.tsunami ?? 'Tidak dinyatakan pada catatan ini; ikuti BMKG' }}</dd></div>
                <div class="wide"><dt>Dirasakan (MMI)</dt><dd>{{ quake.felt ?? 'Belum ada laporan' }}</dd></div>
            </dl>

            <p v-if="quake.shakemapUrl && blackout" class="note">Shakemap disembunyikan di Mode Blackout untuk menghemat data dan baterai.</p>
            <figure v-else-if="quake.shakemapUrl && !shakeFailed" class="shake">
                <figcaption>Shakemap BMKG</figcaption>
                <div class="frame" :class="{ loading: !shakeLoaded }">
                    <img :src="quake.shakemapUrl" :alt="`Peta guncangan (Shakemap) BMKG untuk gempa M${quake.mag.toFixed(1)} ${quake.region}`" loading="lazy" @load="shakeLoaded = true" @error="shakeFailed = true" />
                </div>
            </figure>
            <p v-else-if="quake.shakemapUrl" class="note">Shakemap belum dapat dimuat. <a :href="quake.shakemapUrl" target="_blank" rel="noopener noreferrer">Buka langsung</a></p>
            <p v-else class="note">{{ quake.validation === 'pending' ? 'Rincian tsunami, MMI resmi, dan Shakemap menunggu validasi BMKG.' : 'Shakemap hanya tersedia untuk gempa terbaru dari BMKG.' }}</p>
        </template>
    </section>
</template>

<style scoped>
.detail { border: 1px solid #1f2937; background: #111827; border-radius: 2px; padding: 10px 12px; }
header { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
h3 { margin: 0; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; font-weight: 600; }
.link { background: none; border: 0; color: #38bdf8; font: inherit; font-size: 11px; text-decoration: underline; cursor: pointer; padding: 0; min-height: 28px; }
.link:focus-visible { outline: 2px solid #38bdf8; outline-offset: 2px; }
.val { margin: 8px 0 0; padding: 3px 8px; display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: .04em; border: 1px solid; border-radius: 2px; line-height: 1.4; }
.val.official { color: #bbf7d0; background: #052e16; border-color: #166534; }
.val.pending { color: #fde68a; background: #422006; border-color: #f59e0b; }
.val.fallback { color: #fed7aa; background: #431407; border-color: #f97316; }
.top { display: flex; gap: 12px; align-items: center; margin-top: 8px; }
.mag { flex: none; min-width: 70px; padding: 4px 8px; border: 1px solid; border-radius: 2px; font-size: 34px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; text-align: center; background: #090d16; }
.mag small { font-size: 11px; margin-left: 2px; color: #9ca3af; font-weight: 400; }
.region { margin: 0; font-size: 14px; font-weight: 700; line-height: 1.35; color: #f9fafb; }
.time { margin: 3px 0 0; font-size: 11px; color: #9ca3af; line-height: 1.4; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 12px; margin: 10px 0 0; }
.wide { grid-column: 1 / -1; }
dt { font-size: 10px; color: #9ca3af; }
dd { margin: 2px 0 0; font-size: 13px; color: #e5e7eb; font-variant-numeric: tabular-nums; line-height: 1.4; }
dd.warn { color: #f87171; font-weight: 700; }
.shake { margin: 10px 0 0; }
figcaption { font-size: 10px; color: #9ca3af; margin-bottom: 4px; }
.frame { background: #090d16; border: 1px solid #1f2937; min-height: 60px; }
.frame.loading { min-height: 140px; }
img { display: block; width: 100%; height: auto; }
.note { margin: 10px 0 0; font-size: 11px; color: #9ca3af; line-height: 1.5; }
.note a { color: #38bdf8; }
.empty { margin: 0; color: #9ca3af; font-size: 12px; }
</style>
