<script setup>
import { computed } from 'vue';
import { HOUR_OPTIONS, MAGNITUDE_OPTIONS } from '../composables/useDisasters';
import { formatAgo, formatMagnitude, hasTsunamiPotential, magnitudeColor, magnitudeInk } from '../lib/quake';

const props = defineProps({
    events: { type: Array, required: true },
    hours: { type: Number, required: true },
    minMagnitude: { type: Number, required: true },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
    now: { type: Number, required: true },
    /** Di ponsel filter disembunyikan saat lembar ringkas supaya daftar langsung terlihat. */
    showFilters: { type: Boolean, default: true },
});

const emit = defineEmits(['select', 'set-hours', 'set-magnitude', 'retry']);

const periodLabel = computed(() => HOUR_OPTIONS.find((o) => o.value === props.hours)?.label ?? `${props.hours} jam`);
</script>

<template>
    <!-- Di ponsel seluruh isi (filter dan daftar) bergulir bersama; di layar lebar hanya daftar yang bergulir. -->
    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto md:overflow-visible">
        <div v-if="showFilters" class="space-y-3 border-b border-line px-5 pb-4">
            <fieldset>
                <legend class="mb-1.5 text-xs font-medium text-muted">Rentang waktu</legend>
                <div class="grid grid-cols-3 overflow-hidden rounded-md border border-line">
                    <button
                        v-for="o in HOUR_OPTIONS"
                        :key="o.value"
                        type="button"
                        :aria-pressed="hours === o.value"
                        class="border-r border-line px-2 py-1.5 text-sm last:border-r-0"
                        :class="hours === o.value ? 'bg-ink font-medium text-white' : 'bg-surface text-ink hover:bg-mist'"
                        @click="emit('set-hours', o.value)"
                    >
                        {{ o.label }}
                    </button>
                </div>
            </fieldset>

            <fieldset>
                <legend class="mb-1.5 text-xs font-medium text-muted">Kekuatan minimum</legend>
                <div class="grid grid-cols-4 overflow-hidden rounded-md border border-line">
                    <button
                        v-for="o in MAGNITUDE_OPTIONS"
                        :key="o.value"
                        type="button"
                        :aria-pressed="minMagnitude === o.value"
                        class="border-r border-line px-1 py-1.5 text-sm last:border-r-0"
                        :class="minMagnitude === o.value ? 'bg-ink font-medium text-white' : 'bg-surface text-ink hover:bg-mist'"
                        @click="emit('set-magnitude', o.value)"
                    >
                        {{ o.label }}
                    </button>
                </div>
            </fieldset>
        </div>

        <p class="px-5 py-2.5 text-sm text-muted" aria-live="polite">
            <template v-if="loading">Memuat data…</template>
            <template v-else>{{ events.length }} kejadian dalam {{ periodLabel }} terakhir</template>
        </p>

        <div v-if="error" class="mx-5 mb-3 rounded-md border border-quake-3 bg-quake-3/5 p-3 text-sm" role="alert">
            <p>{{ error }}</p>
            <button type="button" class="mt-2 font-medium underline underline-offset-2" @click="emit('retry')">Coba lagi</button>
        </div>

        <ul v-if="events.length" class="border-t border-line md:min-h-0 md:flex-1 md:overflow-y-auto">
            <li v-for="f in events" :key="f.id" class="border-b border-line last:border-b-0">
                <button
                    type="button"
                    class="grid w-full grid-cols-[3.4rem_1fr_auto] items-start gap-x-3 px-5 py-3 text-left hover:bg-mist"
                    @click="emit('select', f.id)"
                >
                    <span
                        class="mag pt-0.5 text-[1.75rem]"
                        :style="{ color: magnitudeInk(f.properties.magnitude) }"
                        :aria-label="`Magnitudo ${formatMagnitude(f.properties.magnitude)}`"
                    >
                        {{ formatMagnitude(f.properties.magnitude) }}
                    </span>

                    <span class="min-w-0">
                        <span class="block text-sm font-medium leading-snug">{{ f.properties.location }}</span>
                        <span class="mt-0.5 block text-xs text-muted">
                            Kedalaman {{ f.properties.depth_km != null ? Math.round(f.properties.depth_km) : '–' }} km
                        </span>
                        <span
                            v-if="hasTsunamiPotential(f.properties.tsunami_potential)"
                            class="mt-1.5 inline-block rounded bg-tsunami px-1.5 py-0.5 text-xs font-medium text-white"
                        >
                            Berpotensi tsunami
                        </span>
                    </span>

                    <span class="pt-1 text-xs text-muted">{{ formatAgo(f.properties.occurred_at, now) }}</span>
                </button>
            </li>
        </ul>

        <div v-else-if="!loading && !error" class="px-5 py-8 text-sm text-muted">
            <p class="font-medium text-ink">Tidak ada gempa dengan filter ini.</p>
            <p class="mt-1">Perlebar rentang waktu atau turunkan kekuatan minimum.</p>
        </div>
    </div>
</template>
