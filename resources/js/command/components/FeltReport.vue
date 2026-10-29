<script setup>
import { computed } from 'vue';
import { DYFI_LEVELS } from '../lib/dyfi';

// Panel laporan warga "Did You Feel It": 3 pilihan, satu ketukan. Titik laporan ditampilkan di peta sebagai kelompok warna.
const props = defineProps({
    hasLocation: Boolean,
    sending: Boolean,
    result: { type: Object, default: null },
    queued: { type: Number, default: 0 },
    cooldownSeconds: { type: Number, default: 0 },
    count: { type: Number, default: 0 },
    loadError: Boolean,
});
const emit = defineEmits(['report']);

const options = [
    { level: 'none', title: 'Tidak merasakan', hint: 'Tidak ada getaran di tempat saya' },
    { level: 'light', title: 'Getaran ringan atau sedang', hint: 'Benda bergoyang, terasa jelas' },
    { level: 'strong', title: 'Guncangan kuat atau merusak', hint: 'Sulit berdiri, ada kerusakan' },
];
const wait = computed(() => (props.cooldownSeconds > 0 ? `${Math.ceil(props.cooldownSeconds / 60)} mnt` : null));
</script>

<template>
    <section class="dyfi" aria-labelledby="dyfi-h">
        <h3 id="dyfi-h">Laporan warga (DYFI)</h3>
        <p class="note">Apakah Anda merasakan gempa? Satu ketukan, tanpa identitas. Titik dibulatkan sekitar 1 km.</p>
        <div class="opts">
            <button v-for="o in options" :key="o.level" type="button" class="opt" :disabled="sending || cooldownSeconds > 0" :style="{ '--c': DYFI_LEVELS[o.level].color }" @click="emit('report', o.level)">
                <span class="sw" aria-hidden="true"></span>
                <span class="tx"><b>{{ o.title }}</b><small>{{ o.hint }}</small></span>
            </button>
        </div>
        <p v-if="!hasLocation" class="note warn">Aktifkan lokasi di kartu Telemetri agar laporan bisa ditandai di peta.</p>
        <p v-else-if="wait" class="note">Laporan terakhir sudah terkirim. Bisa melapor lagi dalam {{ wait }}.</p>
        <p v-if="result" class="res" :class="result.kind" role="status">{{ result.text }}</p>
        <p v-if="queued" class="note">{{ queued }} laporan menunggu koneksi.</p>
        <p class="note">
            {{ loadError ? 'Laporan warga belum dapat dimuat.' : `${count} laporan warga 6 jam terakhir di peta.` }} Laporan belum diverifikasi dan bukan data resmi.
        </p>
    </section>
</template>

<style scoped>
.dyfi { border: 1px solid #1f2937; background: #111827; border-radius: 2px; padding: 10px 12px; }
h3 { margin: 0; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; font-weight: 600; }
.note { margin: 6px 0 0; font-size: 11px; color: #9ca3af; line-height: 1.5; }
.note.warn { color: #fbbf24; }
.opts { display: grid; gap: 6px; margin-top: 8px; }
.opt { display: flex; align-items: center; gap: 10px; min-height: 48px; padding: 6px 10px; background: #090d16; border: 1px solid #374151; border-left: 4px solid var(--c); border-radius: 2px; color: #e5e7eb; font: inherit; text-align: left; cursor: pointer; }
.opt:hover:not(:disabled) { background: #1f2937; border-color: var(--c); }
.opt:disabled { opacity: .5; cursor: not-allowed; }
.opt:focus-visible { outline: 2px solid #38bdf8; outline-offset: 2px; }
.sw { flex: none; width: 12px; height: 12px; border-radius: 50%; background: var(--c); }
.tx { display: grid; }
.tx b { font-size: 12px; }
.tx small { font-size: 10px; color: #9ca3af; }
.res { margin: 8px 0 0; padding: 6px 8px; font-size: 11px; border: 1px solid; border-radius: 2px; }
.res.terkirim { color: #bbf7d0; background: #052e16; border-color: #22c55e; }
.res.antre, .res.jeda, .res.lokasi { color: #fed7aa; background: #431407; border-color: #f97316; }
.res.ditolak { color: #fecaca; background: #450a0a; border-color: #ef4444; }
@media (max-width: 767px) { .opt { min-height: 56px; } }
</style>
