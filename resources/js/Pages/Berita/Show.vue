<script setup>
import { Head, Link } from '@inertiajs/vue3';
import HeroWaves from '../../components/HeroWaves.vue';
import Icon from '../../components/Icon.vue';
import MagnitudeScale from '../../components/MagnitudeScale.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';
import { formatFullWib, headline, strengthLabel } from '../../lib/berita';
import { SOURCE_LABEL, formatAgo, formatMagnitude, hasTsunamiPotential, magnitudeColor, magnitudeInk } from '../../lib/quake';

const props = defineProps({
    event: { type: Object, required: true },
    affected_camps: { type: Array, required: true },
    nearby: { type: Array, required: true },
});

const p = props.event.properties;
const [lng, lat] = props.event.geometry.coordinates;
const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });

const facts = [
    { icon: 'wave', label: 'Kedalaman', value: p.depth_km != null ? `${Math.round(p.depth_km)} km` : '–' },
    { icon: 'pin', label: 'Koordinat', value: `${lat.toFixed(2)}, ${lng.toFixed(2)}` },
    { icon: 'warning', label: 'Radius dampak', value: p.impact_radius_km != null ? `${nf.format(p.impact_radius_km)} km` : '–' },
    { icon: 'info', label: 'Tsunami', value: p.tsunami_potential ?? 'Tidak disebut sumber' },
];
</script>

<template>
    <Head :title="headline(p)" />

    <PublicLayout>
        <section class="relative overflow-hidden bg-gradient-to-br from-[#0a1630] via-[#10307f] to-[#1f56e8] text-white">
            <HeroWaves />
            <div class="relative mx-auto max-w-4xl px-4 pb-24 pt-8 md:px-6 md:pb-28 md:pt-10">
                <Link href="/berita" class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-4 py-2 text-sm font-bold backdrop-blur-sm transition-colors hover:bg-white/25"><Icon name="chevron" :size="16" class="rotate-180" />Daftar berita</Link>

                <div class="mt-6 flex flex-col gap-6 sm:flex-row sm:items-center">
                    <span class="relative grid size-36 shrink-0 place-items-center sm:size-44">
                        <span class="ripple absolute inset-0 rounded-full border-2" :style="{ borderColor: magnitudeColor(p.magnitude) }" />
                        <span class="ripple absolute inset-0 rounded-full border-2" :style="{ borderColor: magnitudeColor(p.magnitude), animationDelay: '1.4s' }" />
                        <span class="mag relative text-7xl sm:text-8xl" :style="{ color: magnitudeColor(p.magnitude) }">{{ formatMagnitude(p.magnitude) }}</span>
                    </span>
                    <div>
                        <p class="flex flex-wrap items-center gap-2 text-sm font-bold">
                            <span class="rounded-full bg-white/20 px-3 py-1">{{ strengthLabel(p.magnitude) }}</span>
                            <span class="rounded-full bg-white/20 px-3 py-1">{{ SOURCE_LABEL[p.source] ?? p.source }}</span>
                        </p>
                        <h1 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl">{{ headline(p) }}</h1>
                        <p class="mt-2 text-base text-white/85">{{ formatFullWib(p.occurred_at) }}, {{ formatAgo(p.occurred_at) }}</p>
                    </div>
                </div>
            </div>
        </section>

        <article class="mx-auto -mt-14 max-w-4xl space-y-10 px-4 pb-14 md:px-6">
            <ul class="relative z-10 grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="Rincian gempa">
                <li v-for="(f, i) in facts" :key="f.label" v-reveal="i * 60" class="rounded-3xl bg-white p-4 shadow-md ring-1 ring-line">
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-soft text-brand"><Icon :name="f.icon" :size="20" /></span>
                    <p class="mt-3 text-xs font-bold text-muted">{{ f.label }}</p>
                    <p class="mt-0.5 text-lg font-extrabold leading-snug">{{ f.value }}</p>
                </li>
            </ul>

            <p v-if="hasTsunamiPotential(p.tsunami_potential)" class="flex items-start gap-3 rounded-3xl bg-tsunami p-5 text-base font-semibold text-white shadow-md" role="alert">
                <Icon name="warning" :size="26" />Sumber menyebut gempa ini berpotensi tsunami. Jauhi pantai dan ikuti arahan BMKG dan BPBD setempat.
            </p>

            <MagnitudeScale :initial="p.magnitude ?? 5" fixed />

            <div class="flex flex-wrap gap-3">
                <Link href="/peta" class="inline-flex items-center gap-2 rounded-full bg-brand px-6 py-3 text-base font-extrabold text-white shadow-md transition-transform hover:scale-[1.03] active:scale-95"><Icon name="map" :size="20" />Lihat di peta</Link>
                <Link href="/panduan" class="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-base font-extrabold shadow-sm ring-1 ring-line transition-colors hover:bg-brand-soft"><Icon name="shield" :size="20" />Panduan keselamatan</Link>
            </div>

            <section aria-labelledby="h-posko" v-reveal>
                <h2 id="h-posko" class="text-2xl font-extrabold">Posko dalam radius dampak</h2>
                <ul v-if="affected_camps.length" class="mt-3 divide-y divide-line overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-line">
                    <li v-for="c in affected_camps" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-4 text-base">
                        <span class="flex items-center gap-3 font-bold"><span class="grid size-9 place-items-center rounded-xl bg-[#e0f7f2] text-camp"><Icon name="home" :size="18" /></span>{{ c.name }}</span>
                        <span class="text-sm text-muted">{{ nf.format(c.distance_km) }} km dari pusat gempa, {{ c.current_occupants }} dari {{ c.capacity_people }} pengungsi</span>
                    </li>
                </ul>
                <p v-else class="mt-3 rounded-3xl bg-white p-5 text-base text-muted shadow-sm ring-1 ring-line">Tidak ada posko pengungsian tercatat dalam radius dampak gempa ini.</p>
            </section>

            <section v-if="nearby.length" aria-labelledby="h-susulan" v-reveal>
                <h2 id="h-susulan" class="text-2xl font-extrabold">Gempa lain di sekitar (150 km, ±3 hari)</h2>
                <ul class="mt-3 divide-y divide-line overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-line">
                    <li v-for="f in nearby" :key="f.id">
                        <Link :href="`/berita/${f.id}`" class="flex items-center gap-4 px-5 py-3.5 text-base transition-colors hover:bg-brand-soft">
                            <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-mist"><span class="mag text-2xl" :style="{ color: magnitudeInk(f.properties.magnitude) }">{{ formatMagnitude(f.properties.magnitude) }}</span></span>
                            <span class="min-w-0 flex-1 truncate font-bold">{{ f.properties.location }}</span>
                            <span class="shrink-0 text-sm text-muted">{{ formatAgo(f.properties.occurred_at) }}</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <p class="text-sm text-muted">Sumber: {{ SOURCE_LABEL[p.source] ?? p.source }}. Data bisa direvisi sumber setelah dipublikasikan.</p>
        </article>
    </PublicLayout>
</template>
