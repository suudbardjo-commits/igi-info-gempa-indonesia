<script setup>
import { computed } from 'vue';
import { INDONESIA_PATH, MAP, NEIGHBOR_PATH } from '../lib/indonesiaMap';
import { formatMagnitude, magnitudeColor } from '../lib/quake';

/** Peta sebaran gempa: garis pantai Natural Earth, gempa sebagai lingkaran berukuran sesuai magnitudo. Lingkaran bisa dipilih. */
const props = defineProps({
    events: { type: Array, required: true },
    camps: { type: Array, default: () => [] },
    showCamps: { type: Boolean, default: true },
    selectedId: { type: String, default: null },
});

const emit = defineEmits(['select']);

const project = (lng, lat) => [((lng - MAP.lon0) / (MAP.lon1 - MAP.lon0)) * MAP.width, ((MAP.lat0 - lat) / (MAP.lat0 - MAP.lat1)) * MAP.height];

// Kota acuan supaya sebaran terbaca tanpa harus mengenali bentuk pulau.
const CITIES = [
    ['Banda Aceh', 95.32, 5.55],
    ['Medan', 98.67, 3.59],
    ['Jakarta', 106.85, -6.2],
    ['Denpasar', 115.22, -8.65],
    ['Makassar', 119.43, -5.15],
    ['Kupang', 123.6, -10.17],
    ['Jayapura', 140.72, -2.53],
].map(([name, lng, lat]) => ({ name, p: project(lng, lat) }));

const dots = computed(() =>
    props.events
        .map((f) => {
            const [lng, lat] = f.geometry.coordinates;
            const [x, y] = project(lng, lat);
            const mag = f.properties.magnitude ?? 0;

            return { id: f.id, x, y, mag, r: 2.8 + Math.max(mag - 2.5, 0) * 2.6, place: f.properties.location };
        })
        .filter((d) => d.x >= 0 && d.x <= MAP.width && d.y >= 0 && d.y <= MAP.height)
        // Yang kecil digambar belakangan supaya tidak tertutup yang besar; yang terpilih paling akhir.
        .sort((a, b) => (a.id === props.selectedId) - (b.id === props.selectedId) || b.mag - a.mag),
);

const campPins = computed(() =>
    props.showCamps
        ? props.camps.map((f) => ({ id: f.id, p: project(f.geometry.coordinates[0], f.geometry.coordinates[1]), name: f.properties.name })).filter((c) => c.p[0] >= 0 && c.p[0] <= MAP.width)
        : [],
);
</script>

<template>
    <svg :viewBox="`0 0 ${MAP.width} ${MAP.height}`" class="block h-auto w-full" role="group" aria-label="Peta sebaran gempa di Indonesia. Pilih lingkaran untuk melihat rincian gempanya." @click.self="emit('select', null)">
        <rect :width="MAP.width" :height="MAP.height" fill="#0a1630" @click="emit('select', null)" />

        <g stroke="#6fe0ee" stroke-opacity="0.07" stroke-width="1" pointer-events="none">
            <line v-for="lng in [100, 110, 120, 130, 140]" :key="`g${lng}`" :x1="project(lng, 0)[0]" :x2="project(lng, 0)[0]" y1="0" :y2="MAP.height" />
            <line v-for="lat in [5, 0, -5, -10]" :key="`t${lat}`" x1="0" :x2="MAP.width" :y1="project(100, lat)[1]" :y2="project(100, lat)[1]" />
        </g>

        <g pointer-events="none">
            <path :d="NEIGHBOR_PATH" fill="#101f40" stroke="#1d3563" stroke-width="0.8" />
            <path :d="INDONESIA_PATH" fill="#1a3466" stroke="#3a5ea3" stroke-width="0.9" stroke-linejoin="round" />
        </g>

        <g v-for="c in CITIES" :key="c.name" fill="#9fb3d3" font-size="11" font-weight="500" pointer-events="none">
            <circle :cx="c.p[0]" :cy="c.p[1]" r="2" />
            <text :x="c.p[0] + 6" :y="c.p[1] + 4">{{ c.name }}</text>
        </g>

        <rect v-for="c in campPins" :key="c.id" :x="c.p[0] - 3" :y="c.p[1] - 3" width="6" height="6" rx="1.5" fill="#2dd4bf" stroke="#0a1630" stroke-width="1.5" pointer-events="none">
            <title>{{ c.name }}</title>
        </rect>

        <g
            v-for="d in dots"
            :key="d.id"
            class="dot"
            role="button"
            tabindex="0"
            :aria-label="`Gempa M ${formatMagnitude(d.mag)} di ${d.place}`"
            :aria-pressed="d.id === selectedId"
            @click.stop="emit('select', d.id === selectedId ? null : d.id)"
            @keydown.enter.prevent="emit('select', d.id === selectedId ? null : d.id)"
            @keydown.space.prevent="emit('select', d.id === selectedId ? null : d.id)"
        >
            <title>M {{ formatMagnitude(d.mag) }}, {{ d.place }}</title>
            <circle :cx="d.x" :cy="d.y" :r="d.r + 7" fill="transparent" />
            <circle v-if="d.id === selectedId" :cx="d.x" :cy="d.y" :r="d.r" :fill="magnitudeColor(d.mag)" class="live-dot" />
            <circle v-if="d.id === selectedId" :cx="d.x" :cy="d.y" :r="d.r + 5" fill="none" stroke="#fff" stroke-width="2" />
            <circle class="core" :cx="d.x" :cy="d.y" :r="d.r" :fill="magnitudeColor(d.mag)" :fill-opacity="d.id === selectedId ? 1 : 0.82" stroke="#fff" :stroke-opacity="d.id === selectedId ? 1 : 0.65" stroke-width="0.9" />
        </g>
    </svg>
</template>

<style scoped>
.dot {
    cursor: pointer;
    outline: none;
}

.dot:hover .core {
    fill-opacity: 1;
    stroke-opacity: 1;
    stroke-width: 1.8;
}

.dot:focus-visible {
    outline: 2px solid #6fe0ee;
    outline-offset: 3px;
    border-radius: 50%;
}
</style>
