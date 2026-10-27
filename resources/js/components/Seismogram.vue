<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatAgo, formatMagnitude, magnitudeColor } from '../lib/quake';

/**
 * Seismogram dari gempa nyata: garis dasar bergetar halus, dan tiap gempa menambah lonjakan setinggi magnitudonya.
 * Interaktif: geser jari atau kursor untuk membaca waktu dan gempa terdekat di titik itu; gempa M 4,5 ke atas
 * diberi penanda yang bisa diketuk.
 */
const props = defineProps({
    events: { type: Array, required: true },
    now: { type: Number, required: true },
    /** 1 = 24 jam terakhir, 7 = seminggu. */
    days: { type: Number, default: 7 },
});

const box = ref(null);
const width = ref(960);
const height = computed(() => (width.value < 560 ? 200 : 250));
let observer;

onMounted(() => {
    width.value = box.value.clientWidth || width.value;
    observer = new ResizeObserver(([entry]) => (width.value = Math.round(entry.contentRect.width)));
    observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());

const PAD_X = 6;
const HOUR = 3_600_000;
const spanMs = computed(() => props.days * 24 * HOUR);
const startMs = computed(() => props.now - spanMs.value);
const xOf = (ms) => PAD_X + ((ms - startMs.value) / spanMs.value) * (width.value - PAD_X * 2);
const timeOf = (x) => startMs.value + ((x - PAD_X) / (width.value - PAD_X * 2)) * spanMs.value;
const mid = computed(() => height.value * 0.55);

/** Pengacak tetap: getaran dasar identik tiap render, supaya garis tidak "berubah sendiri" saat data diperbarui. */
function rng(seed) {
    return () => {
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const quakes = computed(() =>
    props.events
        .map((f) => ({
            id: f.id,
            mag: f.properties.magnitude ?? 0,
            at: new Date(f.properties.occurred_at).getTime(),
            place: f.properties.location,
        }))
        .filter((q) => q.at >= startMs.value && q.at <= props.now)
        .sort((a, b) => a.at - b.at),
);

/** Tinggi lonjakan (piksel): tumbuh cepat dengan magnitudo, dibatasi supaya M besar tidak keluar bingkai. */
const amplitude = (mag) => Math.min(mid.value - 22, 5 + Math.pow(Math.max(mag - 2, 0), 1.75) * 6.2);

const path = computed(() => {
    const rand = rng(7);
    const step = 2;
    const parts = [];
    // Pada rentang pendek lonjakan lebih renggang, jadi peluruhan dibuat lebih panjang supaya bentuknya tetap terbaca.
    const decay = props.days <= 1 ? 60 : 34;
    const jobs = quakes.value.map((q) => ({ x: xOf(q.at), a: amplitude(q.mag), sign: (q.mag * 10) % 2 < 1 ? 1 : -1 }));

    for (let x = PAD_X; x <= width.value - PAD_X; x += step) {
        let y = mid.value + (rand() - 0.5) * 3.2;

        for (const j of jobs) {
            const dx = x - j.x;
            if (dx < -2 || dx > decay * 4.4) continue;
            y += dx < 0 ? 0 : j.sign * j.a * Math.exp(-dx / decay) * Math.sin(dx * (props.days <= 1 ? 0.36 : 0.62));
        }

        parts.push(`${x.toFixed(1)} ${y.toFixed(1)}`);
    }

    return `M${parts.join('L')}`;
});

const marks = computed(() => {
    const all = quakes.value.filter((q) => q.mag >= 4.5).map((q) => ({ ...q, x: xOf(q.at), y: mid.value - amplitude(q.mag) - 10, label: false }));
    const placed = [];

    // Label angka hanya untuk yang tidak berimpit dengan label lain; yang paling kuat diutamakan.
    for (const m of [...all].sort((a, b) => b.mag - a.mag)) {
        if (placed.every((x) => Math.abs(x - m.x) >= 30)) {
            m.label = true;
            placed.push(m.x);
        }
    }

    return all;
});

const ticks = computed(() => {
    const out = [];

    if (props.days <= 1) {
        // Tiap 6 jam menurut WIB (UTC+7).
        const fmt = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' });
        const base = Math.floor((props.now + 7 * HOUR) / (6 * HOUR)) * 6 * HOUR - 7 * HOUR;

        for (let t = base; t > startMs.value; t -= 6 * HOUR) out.push({ x: xOf(t), label: `${fmt.format(new Date(t)).replace('.', ':')}:00` });

        return out;
    }

    const fmt = new Intl.DateTimeFormat('id-ID', { weekday: 'short', timeZone: 'Asia/Jakarta' });
    // Batas hari menurut WIB: tengah malam WIB = 17:00 UTC hari sebelumnya.
    const wibMidnight = (ms) => Math.floor((ms + 7 * HOUR) / (24 * HOUR)) * 24 * HOUR - 7 * HOUR;

    for (let t = wibMidnight(props.now); t > startMs.value; t -= 24 * HOUR) out.push({ x: xOf(t), label: fmt.format(new Date(t + 12 * HOUR)) });

    return out;
});

// Pembacaan saat digeser: waktu di titik itu dan gempa terdekat dalam 20 piksel.
const scrub = ref(null);
const stamp = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' });

function onMove(e) {
    const rect = box.value.getBoundingClientRect();
    const x = Math.min(width.value - PAD_X, Math.max(PAD_X, e.clientX - rect.left));
    const t = timeOf(x);
    const near = quakes.value.reduce((best, q) => (Math.abs(xOf(q.at) - x) <= 20 && (!best || q.mag > best.mag) ? q : best), null);

    scrub.value = { x, at: t, quake: near };
}

const readout = computed(() => {
    if (!scrub.value) return null;
    const s = scrub.value;

    return {
        left: Math.min(Math.max(s.x, 84), width.value - 84),
        time: `${stamp.format(new Date(s.at)).replace('.', ':')} WIB`,
        quake: s.quake,
    };
});
</script>

<template>
    <div ref="box" class="relative w-full select-none" style="touch-action: pan-y" @pointermove="onMove" @pointerdown="onMove" @pointerleave="scrub = null">
        <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" role="img" :aria-label="`Seismogram ${days <= 1 ? '24 jam' : 'tujuh hari'} terakhir dari gempa yang tercatat`">
            <g stroke="#6fe0ee" stroke-opacity="0.12">
                <line v-for="t in ticks" :key="t.x" :x1="t.x" :x2="t.x" y1="8" :y2="height - 26" />
                <line :x1="PAD_X" :x2="width - PAD_X" :y1="mid" :y2="mid" stroke-dasharray="2 5" />
            </g>

            <text v-for="t in ticks" :key="`l${t.x}`" :x="t.x + 6" :y="height - 8" fill="#8fa3c2" font-size="12" font-weight="500">{{ t.label }}</text>

            <path :d="path" pathLength="1" fill="none" stroke="#6fe0ee" stroke-width="1.6" stroke-linejoin="round" class="trace-draw" />

            <line v-if="scrub" :x1="scrub.x" :x2="scrub.x" y1="8" :y2="height - 26" stroke="#fff" stroke-opacity="0.55" />

            <a v-for="m in marks" :key="m.id" :href="`/berita/${m.id}`" :aria-label="`Gempa M ${formatMagnitude(m.mag)} di ${m.place}`">
                <title>M {{ formatMagnitude(m.mag) }}, {{ m.place }}</title>
                <circle :cx="m.x" :cy="m.y" r="16" fill="transparent" />
                <circle :cx="m.x" :cy="m.y" :r="scrub?.quake?.id === m.id ? 7 : 5" :fill="magnitudeColor(m.mag)" stroke="#0a1630" stroke-width="2" />
                <text v-if="m.label" :x="m.x" :y="m.y - 12" text-anchor="middle" fill="#fff" font-family="var(--font-display)" font-size="18" font-weight="800">{{ formatMagnitude(m.mag) }}</text>
            </a>

            <g :transform="`translate(${width - PAD_X} ${mid})`">
                <circle r="4" fill="#6fe0ee" class="live-dot" />
                <circle r="4" fill="#6fe0ee" />
            </g>
        </svg>

        <div v-if="readout" class="pointer-events-none absolute top-1 z-10 w-40 -translate-x-1/2 rounded-md bg-white px-2.5 py-1.5 text-xs leading-snug text-ink shadow-lg" :style="{ left: `${readout.left}px` }" aria-hidden="true">
            <p class="font-semibold">{{ readout.time }}</p>
            <p v-if="readout.quake" class="mt-0.5">
                <span class="mag text-base">M {{ formatMagnitude(readout.quake.mag) }}</span>
                <span class="block truncate text-muted">{{ readout.quake.place }}</span>
                <span class="text-muted">{{ formatAgo(new Date(readout.quake.at).toISOString(), now) }}</span>
            </p>
            <p v-else class="text-muted">Tidak ada gempa tercatat di sini</p>
        </div>
    </div>
</template>
