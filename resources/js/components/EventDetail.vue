<script setup>
import { computed } from 'vue';
import {
    SOURCE_LABEL,
    formatAgo,
    formatMagnitude,
    formatWib,
    hasTsunamiPotential,
    magnitudeColor,
    magnitudeInk,
} from '../lib/quake';

const props = defineProps({
    feature: { type: Object, required: true },
    detail: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    now: { type: Number, required: true },
});

const emit = defineEmits(['back']);

const p = computed(() => props.feature.properties);
const camps = computed(() => props.detail?.affected_camps ?? []);
const tsunami = computed(() => hasTsunamiPotential(p.value.tsunami_potential));

function occupancy(c) {
    return c.capacity_people ? Math.min(100, Math.round((c.current_occupants / c.capacity_people) * 100)) : 0;
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
        <div class="px-5 pb-5">
            <button type="button" class="-ml-1 rounded px-1 py-1 text-sm font-medium text-muted hover:text-ink" @click="emit('back')">
                Kembali ke daftar
            </button>

            <div class="mt-3 flex items-end gap-4">
                <span class="mag text-[4.5rem]" :style="{ color: magnitudeInk(p.magnitude) }">
                    {{ formatMagnitude(p.magnitude) }}
                </span>
                <span class="pb-2 text-sm text-muted">magnitudo</span>
            </div>

            <h2 class="mt-3 text-lg font-semibold leading-snug">{{ p.location }}</h2>
            <p class="mt-1 text-sm text-muted">{{ formatWib(p.occurred_at) }}, {{ formatAgo(p.occurred_at, now) }}</p>

            <div v-if="tsunami" class="mt-4 border-l-4 border-tsunami bg-tsunami/5 px-3 py-2 text-sm">
                <p class="font-medium text-tsunami">Berpotensi tsunami</p>
                <p class="mt-0.5">{{ p.tsunami_potential }}</p>
            </div>
            <p v-else-if="p.tsunami_potential" class="mt-4 text-sm text-muted">{{ p.tsunami_potential }}</p>
        </div>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 border-y border-line px-5 py-4 text-sm">
            <div>
                <dt class="text-xs text-muted">Kedalaman</dt>
                <dd class="mt-0.5 font-medium">{{ p.depth_km != null ? `${Math.round(p.depth_km)} km` : 'Belum diketahui' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted">Sumber data</dt>
                <dd class="mt-0.5 font-medium">{{ SOURCE_LABEL[p.source] ?? p.source }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-xs text-muted">Radius dampak (perkiraan)</dt>
                <dd class="mt-0.5 font-medium">{{ p.impact_radius_km }} km</dd>
                <dd class="mt-0.5 text-xs text-muted">Perkiraan kasar dari magnitudo, bukan peta guncangan resmi.</dd>
            </div>
        </dl>

        <section class="px-5 py-4" aria-labelledby="camps-heading">
            <h3 id="camps-heading" class="text-sm font-semibold">Posko dalam radius dampak</h3>

            <p v-if="loading" class="mt-2 text-sm text-muted">Memuat posko…</p>
            <p v-else-if="detail?.failed" class="mt-2 text-sm text-quake-3" role="alert">
                Daftar posko tidak bisa dimuat. Tutup rincian ini lalu pilih gempa lagi.
            </p>
            <p v-else-if="!camps.length" class="mt-2 text-sm text-muted">Tidak ada posko terdaftar dalam radius ini.</p>

            <ul v-else class="mt-2 divide-y divide-line">
                <li v-for="c in camps" :key="c.id" class="py-2.5">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-sm font-medium">{{ c.name }}</span>
                        <span class="shrink-0 text-sm text-muted">{{ c.distance_km.toLocaleString('id-ID') }} km</span>
                    </div>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-mist" aria-hidden="true">
                        <div class="h-full rounded-full bg-camp" :style="{ width: `${occupancy(c)}%` }" />
                    </div>
                    <p class="mt-1 text-xs text-muted">
                        {{ c.current_occupants }} dari {{ c.capacity_people }} orang ({{ occupancy(c) }}% terisi)
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
