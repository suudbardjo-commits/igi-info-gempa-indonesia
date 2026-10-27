<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AnnouncementList from '../../components/AnnouncementList.vue';
import Icon from '../../components/Icon.vue';
import PageHero from '../../components/PageHero.vue';
import OfficialNews from '../../components/OfficialNews.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';
import { HOUR_OPTIONS, MAGNITUDE_OPTIONS } from '../../composables/useDisasters';
import { headline, strengthLabel, summary } from '../../lib/berita';
import { SOURCE_LABEL, formatAgo, formatMagnitude, hasTsunamiPotential, magnitudeColor, magnitudeInk } from '../../lib/quake';

const props = defineProps({
    events: { type: Array, required: true },
    pagination: { type: Object, required: true },
    filters: { type: Object, required: true },
    announcements: { type: Array, required: true },
    official: { type: Object, default: null },
});

const SOURCES = [
    { value: null, label: 'Semua sumber' },
    { value: 'bmkg', label: 'BMKG' },
    { value: 'usgs', label: 'USGS' },
];

function go(change, page = 1) {
    const next = { ...props.filters, ...change };

    router.get(
        '/berita',
        {
            hours: next.hours,
            min_magnitude: next.min_magnitude || undefined,
            source: next.source || undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveScroll: true, preserveState: true, only: ['events', 'pagination', 'filters'] },
    );
}
</script>

<template>
    <Head title="Berita terkini" />

    <PublicLayout>
        <PageHero title="Berita terkini" icon="news" subtitle="Pengumuman petugas, kabar dari BMKG dan BNPB dengan tautan ke sumbernya, dan buletin gempa otomatis dari data yang tercatat." />

        <div class="mx-auto -mt-16 max-w-4xl px-4 pb-14 md:px-6">
            <div v-if="announcements.length" class="relative z-10"><AnnouncementList :items="announcements" /></div>

            <section class="mt-8" aria-labelledby="h-resmi" v-reveal>
                <h2 id="h-resmi" class="text-2xl font-extrabold">Kabar dari BMKG dan BNPB</h2>
                <div class="mt-3">
                    <OfficialNews v-if="official" :items="official.items" :sources="official.sources" />
                    <p v-else class="text-sm text-muted">Memuat…</p>
                </div>
            </section>

            <h2 class="mt-12 text-2xl font-extrabold" v-reveal>Kejadian gempa</h2>

            <div class="mt-4 grid gap-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-line md:grid-cols-3" role="group" aria-label="Saring gempa">
                <fieldset>
                    <legend class="mb-2 text-sm font-bold text-muted">Rentang waktu</legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="o in HOUR_OPTIONS"
                            :key="o.value"
                            type="button"
                            :aria-pressed="filters.hours === o.value"
                            class="rounded-full px-4 py-2 text-sm font-bold transition-colors"
                            :class="filters.hours === o.value ? 'bg-brand text-white shadow-sm' : 'bg-mist text-ink hover:bg-brand-soft'"
                            @click="go({ hours: o.value })"
                        >
                            {{ o.label }}
                        </button>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="mb-2 text-sm font-bold text-muted">Kekuatan minimum</legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="o in MAGNITUDE_OPTIONS"
                            :key="o.value"
                            type="button"
                            :aria-pressed="filters.min_magnitude === o.value"
                            class="rounded-full px-4 py-2 text-sm font-bold transition-colors"
                            :class="filters.min_magnitude === o.value ? 'bg-brand text-white shadow-sm' : 'bg-mist text-ink hover:bg-brand-soft'"
                            @click="go({ min_magnitude: o.value })"
                        >
                            {{ o.label }}
                        </button>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="mb-2 text-sm font-bold text-muted">Sumber</legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="o in SOURCES"
                            :key="o.label"
                            type="button"
                            :aria-pressed="filters.source === o.value"
                            class="rounded-full px-4 py-2 text-sm font-bold transition-colors"
                            :class="filters.source === o.value ? 'bg-brand text-white shadow-sm' : 'bg-mist text-ink hover:bg-brand-soft'"
                            @click="go({ source: o.value })"
                        >
                            {{ o.label }}
                        </button>
                    </div>
                </fieldset>
            </div>

            <p class="mt-5 text-base font-bold text-muted" aria-live="polite">{{ pagination.total }} berita</p>

            <ul v-if="events.length" class="mt-3 space-y-3">
                <li v-for="(f, i) in events" :key="f.id" v-reveal="Math.min(i, 5) * 50">
                    <Link :href="`/berita/${f.id}`" class="lift group flex gap-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-line md:p-5">
                        <span class="grid size-20 shrink-0 place-items-center rounded-2xl bg-mist transition-transform group-hover:scale-105 md:size-24">
                            <span class="mag text-4xl md:text-5xl" :style="{ color: magnitudeInk(f.properties.magnitude) }">{{ formatMagnitude(f.properties.magnitude) }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-bold text-muted">
                                <span class="rounded-full bg-brand-soft px-2.5 py-0.5 text-brand">{{ strengthLabel(f.properties.magnitude) }}</span>
                                <span>{{ SOURCE_LABEL[f.properties.source] ?? f.properties.source }}</span>
                                <span>{{ formatAgo(f.properties.occurred_at) }}</span>
                            </span>
                            <h3 class="mt-1.5 text-lg font-extrabold leading-snug">{{ headline(f.properties) }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-muted">{{ summary(f.properties) }}</p>
                            <span v-if="hasTsunamiPotential(f.properties.tsunami_potential)" class="mt-2 inline-block rounded-full bg-tsunami px-3 py-1 text-xs font-bold text-white">Berpotensi tsunami</span>
                        </span>
                        <Icon name="chevron" :size="22" class="mt-1 hidden self-center text-muted transition-transform group-hover:translate-x-1 sm:block" />
                    </Link>
                </li>
            </ul>

            <div v-else class="mt-3 rounded-3xl bg-white p-8 text-center shadow-sm ring-1 ring-line">
                <p class="text-lg font-extrabold">Tidak ada berita dengan filter ini.</p>
                <p class="mt-1 text-muted">Perlebar rentang waktu atau turunkan kekuatan minimum.</p>
            </div>

            <nav v-if="pagination.last > 1" class="mt-6 flex flex-wrap items-center justify-between gap-3 text-base" aria-label="Halaman">
                <button type="button" class="rounded-full bg-white px-5 py-2.5 font-bold shadow-sm ring-1 ring-line transition-colors hover:bg-brand-soft disabled:opacity-40" :disabled="pagination.current <= 1" @click="go({}, pagination.current - 1)">Sebelumnya</button>
                <span class="order-first w-full text-center font-semibold text-muted sm:order-none sm:w-auto">Halaman {{ pagination.current }} dari {{ pagination.last }}</span>
                <button type="button" class="rounded-full bg-brand px-5 py-2.5 font-bold text-white shadow-sm transition-colors hover:bg-deep disabled:opacity-40" :disabled="pagination.current >= pagination.last" @click="go({}, pagination.current + 1)">Berikutnya</button>
            </nav>
        </div>
    </PublicLayout>
</template>
