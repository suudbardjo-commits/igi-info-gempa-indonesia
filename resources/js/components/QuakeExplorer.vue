<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { haversineKm, pointOf } from '../lib/distance';
import { SOURCE_LABEL, formatAgo, formatMagnitude, formatWib, hasTsunamiPotential, magnitudeColor } from '../lib/quake';
import QuakeScatter from './QuakeScatter.vue';

/**
 * Penjelajah gempa: saring menurut waktu dan kekuatan, pilih lingkaran untuk melihat rincian dan posko terdekat.
 * Dirancang untuk latar gelap.
 */
const props = defineProps({
    events: { type: Array, required: true },
    camps: { type: Array, required: true },
    now: { type: Number, required: true },
});

const windowHours = ref(168);
const minMag = ref(0);
const showCamps = ref(true);
const selectedId = ref(null);

const WINDOWS = [
    { value: 24, label: '24 jam' },
    { value: 168, label: '7 hari' },
];
const MAGS = [
    { value: 0, label: 'Semua' },
    { value: 4.5, label: 'M 4,5+' },
    { value: 5, label: 'M 5+' },
    { value: 6, label: 'M 6+' },
];

const shown = computed(() =>
    props.events.filter((f) => props.now - new Date(f.properties.occurred_at).getTime() <= windowHours.value * 3_600_000 && (f.properties.magnitude ?? 0) >= minMag.value),
);

// Bila filter mengeluarkan gempa yang sedang dipilih, pilihan dilepas supaya kartu rincian tidak menampilkan yang tak terlihat di peta.
const selected = computed(() => shown.value.find((f) => f.id === selectedId.value) ?? null);

const NEAR_KM = 300;

// Posko dihitung "dekat" hanya dalam 300 km. Bila tidak ada, yang terdekat tetap disebut apa adanya, supaya jarak ribuan
// kilometer tidak terbaca seolah-olah relevan.
const campsByDistance = computed(() => {
    if (!selected.value) return [];
    const here = pointOf(selected.value);

    return props.camps.map((c) => ({ c, km: haversineKm(here, pointOf(c)) })).sort((a, b) => a.km - b.km);
});
const nearestCamps = computed(() => campsByDistance.value.filter((n) => n.km <= NEAR_KM).slice(0, 2));
const farthestNote = computed(() => (nearestCamps.value.length || !campsByDistance.value.length ? null : campsByDistance.value[0]));

const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
const chip = (on) => (on ? 'bg-white text-trench font-bold' : 'border border-white/30 text-white/85 hover:bg-white/10');
</script>

<template>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1.75fr_1fr]">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-3" role="group" aria-label="Saring gempa di peta">
                <div class="flex gap-1.5" role="group" aria-label="Rentang waktu">
                    <button v-for="w in WINDOWS" :key="w.value" type="button" :aria-pressed="windowHours === w.value" class="rounded-md px-3 py-1.5 text-sm" :class="chip(windowHours === w.value)" @click="windowHours = w.value">{{ w.label }}</button>
                </div>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Kekuatan minimum">
                    <button v-for="m in MAGS" :key="m.value" type="button" :aria-pressed="minMag === m.value" class="rounded-md px-3 py-1.5 text-sm" :class="chip(minMag === m.value)" @click="minMag = m.value">{{ m.label }}</button>
                </div>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-white/85">
                    <input v-model="showCamps" type="checkbox" class="size-4 accent-[#2dd4bf]" />
                    Tampilkan posko
                </label>
            </div>

            <!-- Di layar sempit peta dibuat selebar 760 px dan bisa digeser, supaya lingkaran gempa cukup besar untuk diketuk. -->
            <div class="mt-3 overflow-x-auto rounded-xl border border-white/15" tabindex="0" role="region" aria-label="Peta sebaran gempa, bisa digeser mendatar">
                <div class="min-w-[760px] md:min-w-0">
                    <QuakeScatter :events="shown" :camps="camps" :show-camps="showCamps" :selected-id="selected?.id ?? null" @select="selectedId = $event" />
                </div>
            </div>
            <p class="mt-2 text-sm text-white/70" aria-live="polite">{{ shown.length }} gempa tampil. Pilih lingkaran untuk melihat rinciannya.<span class="md:hidden"> Geser peta ke samping untuk melihat seluruh Indonesia.</span></p>
        </div>

        <aside class="self-start rounded-xl border border-white/15 bg-white/[0.06] p-5" aria-live="polite" aria-label="Rincian gempa terpilih">
            <template v-if="selected">
                <div class="flex items-start gap-4">
                    <span class="mag text-7xl" :style="{ color: magnitudeColor(selected.properties.magnitude) }">{{ formatMagnitude(selected.properties.magnitude) }}</span>
                    <button type="button" class="ml-auto rounded-md border border-white/30 px-2.5 py-1 text-xs font-semibold text-white/85 hover:bg-white/10" @click="selectedId = null">Tutup</button>
                </div>
                <h3 class="mt-2 font-display text-2xl leading-tight">{{ selected.properties.location }}</h3>
                <p class="mt-1 text-sm text-white/75">{{ formatWib(selected.properties.occurred_at) }}, {{ formatAgo(selected.properties.occurred_at, now) }}</p>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-white/60">Kedalaman</dt><dd class="font-semibold">{{ selected.properties.depth_km != null ? `${nf.format(selected.properties.depth_km)} km` : 'belum ada' }}</dd></div>
                    <div><dt class="text-xs text-white/60">Sumber</dt><dd class="font-semibold">{{ SOURCE_LABEL[selected.properties.source] ?? selected.properties.source }}</dd></div>
                </dl>
                <p v-if="hasTsunamiPotential(selected.properties.tsunami_potential)" class="mt-3 rounded bg-tsunami px-2 py-1 text-sm font-semibold">Berpotensi tsunami. Ikuti arahan BMKG dan BPBD.</p>

                <h4 class="mt-4 text-sm font-semibold">Posko dalam {{ NEAR_KM }} km dari titik gempa</h4>
                <ul v-if="nearestCamps.length" class="mt-1 divide-y divide-white/15 text-sm">
                    <li v-for="n in nearestCamps" :key="n.c.id" class="flex items-baseline justify-between gap-3 py-2">
                        <span class="min-w-0 truncate font-semibold">{{ n.c.properties.name }}</span>
                        <span class="shrink-0 text-xs text-white/70">{{ nf.format(n.km) }} km, {{ n.c.properties.current_occupants }} dari {{ n.c.properties.capacity_people }} terisi</span>
                    </li>
                </ul>
                <p v-else-if="farthestNote" class="mt-1 text-sm leading-relaxed text-white/70">Tidak ada posko yang tercatat di sistem dalam {{ NEAR_KM }} km. Yang terdekat: {{ farthestNote.c.properties.name }}, {{ nf.format(farthestNote.km) }} km.</p>
                <p v-else class="mt-1 text-sm text-white/70">Belum ada posko aktif yang tercatat.</p>

                <Link :href="`/berita/${selected.id}`" class="mt-4 inline-block rounded-md bg-white px-4 py-2.5 text-sm font-bold text-trench hover:bg-trace">Baca beritanya</Link>
            </template>

            <template v-else>
                <h3 class="font-display text-2xl leading-tight">Pilih gempa di peta</h3>
                <p class="mt-2 text-sm leading-relaxed text-white/75">Rincian, kedalaman, dan posko terdekat dari titik gempa tampil di sini. Dengan papan ketik, tekan Tab untuk berpindah antar lingkaran lalu Enter untuk memilih.</p>
                <ul class="mt-4 space-y-1.5 text-xs text-white/70" aria-label="Keterangan">
                    <li class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#e9b949]" />di bawah M 4,5</li>
                    <li class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#f08a24]" />M 4,5 sampai 5,4</li>
                    <li class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#e5412d]" />M 5,5 sampai 6,4</li>
                    <li class="flex items-center gap-2"><span class="size-3 rounded-full bg-[#a3123a] ring-1 ring-white/50" />M 6,5 ke atas</li>
                    <li class="flex items-center gap-2"><span class="size-3 rounded-[3px] bg-[#2dd4bf]" />posko pengungsian</li>
                </ul>
            </template>
        </aside>
    </div>
</template>
