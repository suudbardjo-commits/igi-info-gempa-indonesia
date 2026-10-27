<script setup>
import { computed, ref } from 'vue';
import { strengthLabel } from '../lib/berita';
import { formatMagnitude, magnitudeInk } from '../lib/quake';

/**
 * Skala magnitudo yang bisa digeser. Kelas dan dampaknya mengikuti klasifikasi USGS (diparafrasekan); frekuensi adalah
 * perkiraan USGS untuk seluruh dunia. Magnitudo itu logaritmik: naik 1 berarti getaran tanah 10 kali lebih besar dan
 * energi sekitar 32 kali lebih besar.
 */
const props = defineProps({
    initial: { type: Number, default: 5 },
    /** Gempa terkuat minggu ini, untuk tombol pintas ke kenyataan. */
    strongest: { type: Object, default: null },
    /** Tanpa penggeser: hanya menjelaskan satu magnitudo (dipakai di halaman berita). */
    fixed: { type: Boolean, default: false },
});

const m = ref(Math.min(8.5, Math.max(2, props.initial)));

const CLASSES = [
    { max: 3, effect: 'Tidak terasa oleh manusia, hanya terekam alat.', per: 'sekitar 1,3 juta per tahun (M 2 sampai 2,9)' },
    { max: 4, effect: 'Sering terasa, tetapi hanya merusak ringan.', per: 'sekitar 130 ribu per tahun' },
    { max: 5, effect: 'Terasa jelas: benda di dalam rumah bergetar dan berbunyi. Kerusakan berarti jarang.', per: 'sekitar 13 ribu per tahun' },
    { max: 6, effect: 'Bisa merusak berat bangunan yang dibuat asal-asalan di wilayah kecil. Bangunan yang dirancang baik paling banyak rusak ringan.', per: 'sekitar 1.300 per tahun' },
    { max: 7, effect: 'Bisa merusak di wilayah berpenduduk sekitar 160 km lebarnya.', per: 'sekitar 130 per tahun' },
    { max: 8, effect: 'Menyebabkan kerusakan serius pada wilayah yang luas.', per: 'sekitar 15 per tahun' },
    { max: Infinity, effect: 'Bisa menghancurkan permukiman di dekat pusat gempa.', per: 'sekitar 1 per tahun' },
];

const info = computed(() => CLASSES.find((c) => m.value < c.max));
const label = computed(() => strengthLabel(m.value));
const energy = computed(() => 10 ** (1.5 * (m.value - 4)));
const energyText = computed(() => {
    const e = energy.value;
    if (Math.abs(e - 1) < 0.05) return 'sama dengan M 4';

    return e > 1
        ? `sekitar ${e.toLocaleString('id-ID', { maximumSignificantDigits: 3 })} kali energi gempa M 4`
        : `sekitar ${(1 / e).toLocaleString('id-ID', { maximumSignificantDigits: 3 })} kali lebih kecil dari gempa M 4`;
});

// Jari-jari cincin tumbuh dengan magnitudo; lonjakan visualnya lebih landai daripada energi sebenarnya supaya tetap muat.
const radius = computed(() => 14 + (m.value - 2) * 15);
const shown = computed(() => m.value.toFixed(1));

const PRESETS = [3, 5, 7];
</script>

<template>
    <div class="grid items-center gap-6 rounded-xl border border-line bg-surface p-5 md:grid-cols-[14rem_1fr] md:gap-8 md:p-6">
        <div class="relative mx-auto grid aspect-square w-48 place-items-center md:w-56" aria-hidden="true">
            <svg viewBox="0 0 240 240" class="absolute inset-0 size-full">
                <circle cx="120" cy="120" :r="radius * 1.55" fill="none" stroke="#14264f" stroke-opacity="0.12" stroke-width="2" class="ring-slow" />
                <circle cx="120" cy="120" :r="radius * 1.18" fill="none" stroke="#14264f" stroke-opacity="0.25" stroke-width="2" />
                <circle cx="120" cy="120" :r="radius" :fill="magnitudeInk(m)" fill-opacity="0.18" :stroke="magnitudeInk(m)" stroke-width="3" class="core" />
                <circle cx="120" cy="120" r="5" :fill="magnitudeInk(m)" />
            </svg>
        </div>

        <div class="min-w-0">
            <p class="flex items-baseline gap-3">
                <span class="mag text-7xl" :style="{ color: magnitudeInk(m) }">{{ formatMagnitude(m) }}</span>
                <span class="font-display text-3xl leading-none">{{ label }}</span>
            </p>
            <p class="mt-2 max-w-xl text-base leading-relaxed" aria-live="polite">{{ info.effect }}</p>
            <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-muted">Energi dibanding M 4</dt><dd class="font-semibold">{{ energyText }}</dd></div>
                <div><dt class="text-xs text-muted">Seberapa sering di seluruh dunia (perkiraan USGS)</dt><dd class="font-semibold">{{ info.per }}</dd></div>
            </dl>

            <div v-if="!fixed" class="mt-5">
                <label for="skala-m" class="block text-sm font-semibold">Geser untuk mencoba magnitudo lain: <span class="mag text-lg">{{ shown.replace('.', ',') }}</span></label>
                <input id="skala-m" v-model.number="m" type="range" min="2" max="8.5" step="0.1" class="mt-2 h-11 w-full cursor-pointer accent-[#14264f]" :aria-valuetext="`Magnitudo ${shown.replace('.', ',')}, ${label}`" />
                <div class="mt-1 flex flex-wrap gap-2">
                    <button v-for="p in PRESETS" :key="p" type="button" class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold hover:bg-mist" @click="m = p">M {{ p }}</button>
                    <button v-if="strongest" type="button" class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold hover:bg-mist" @click="m = Math.min(8.5, Math.max(2, strongest.magnitude))">
                        Terkuat minggu ini: M {{ formatMagnitude(strongest.magnitude) }}
                    </button>
                </div>
            </div>
            <p class="mt-4 text-xs leading-relaxed text-muted">Naik satu angka magnitudo berarti getaran tanah 10 kali lebih besar dan energi sekitar 32 kali lebih besar. Dampak nyata juga bergantung pada kedalaman, jarak, jenis tanah, dan kualitas bangunan.</p>
        </div>
    </div>
</template>

<style scoped>
.core {
    transition: r 0.25s ease;
}

.ring-slow {
    animation: ring-breathe 3.2s ease-in-out infinite;
    transform-origin: 120px 120px;
}

@keyframes ring-breathe {
    0%,
    100% {
        opacity: 0.6;
    }
    50% {
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .ring-slow,
    .core {
        animation: none;
        transition: none;
    }
}
</style>
