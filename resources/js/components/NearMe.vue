<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { haversineKm } from '../lib/distance';
import { formatAgo, formatMagnitude, magnitudeInk } from '../lib/quake';

/**
 * Gempa dan posko terdekat dari posisi pengguna. Posisi dibaca hanya setelah tombol ditekan, dihitung di peramban,
 * dan tidak dikirim ke server mana pun.
 */
const props = defineProps({
    events: { type: Array, required: true },
    camps: { type: Array, required: true },
});

const RADIUS_KM = 300;
const state = ref('idle'); // idle | loading | ready | denied | unsupported | failed
const here = ref(null);

const km = haversineKm;

const quakes = computed(() =>
    here.value
        ? props.events
              .map((f) => ({ f, d: km(here.value, { lng: f.geometry.coordinates[0], lat: f.geometry.coordinates[1] }) }))
              .filter((x) => x.d <= RADIUS_KM)
              .sort((a, b) => new Date(b.f.properties.occurred_at) - new Date(a.f.properties.occurred_at))
              .slice(0, 5)
        : [],
);

const camps = computed(() =>
    here.value
        ? props.camps
              .map((f) => ({ f, d: km(here.value, { lng: f.geometry.coordinates[0], lat: f.geometry.coordinates[1] }) }))
              .sort((a, b) => a.d - b.d)
              .slice(0, 3)
        : [],
);

function locate() {
    if (!('geolocation' in navigator)) {
        state.value = 'unsupported';

        return;
    }

    state.value = 'loading';
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            here.value = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            state.value = 'ready';
        },
        (err) => (state.value = err.code === 1 ? 'denied' : 'failed'),
        { enableHighAccuracy: false, timeout: 12_000, maximumAge: 300_000 },
    );
}

const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
</script>

<template>
    <div class="rounded-xl border border-line bg-surface p-4 md:p-5">
        <h3 class="font-display text-2xl leading-none">Dekat Anda</h3>

        <template v-if="state === 'idle' || state === 'loading'">
            <p class="mt-2 text-sm leading-relaxed text-muted">Lihat gempa dalam {{ RADIUS_KM }} km dan posko pengungsian terdekat. Posisi Anda dibaca di perangkat ini saja dan tidak dikirim ke server.</p>
            <button type="button" class="mt-3 rounded-md bg-trench px-4 py-2.5 text-sm font-bold text-white hover:bg-deep disabled:opacity-60" :disabled="state === 'loading'" @click="locate">
                {{ state === 'loading' ? 'Mencari posisi Anda…' : 'Cek gempa dan posko dekat saya' }}
            </button>
        </template>

        <p v-else-if="state === 'denied'" class="mt-2 text-sm leading-relaxed" role="alert">
            Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di pengaturan peramban, lalu
            <button type="button" class="font-semibold underline underline-offset-2" @click="locate">coba lagi</button>.
        </p>
        <p v-else-if="state === 'unsupported'" class="mt-2 text-sm leading-relaxed" role="alert">Peramban ini tidak bisa membaca lokasi. Gunakan <Link href="/peta" class="font-semibold underline underline-offset-2">peta</Link> untuk mencari secara manual.</p>
        <p v-else-if="state === 'failed'" class="mt-2 text-sm leading-relaxed" role="alert">
            Posisi Anda belum terbaca (sinyal GPS lemah atau terlalu lama). <button type="button" class="font-semibold underline underline-offset-2" @click="locate">Coba lagi</button>.
        </p>

        <template v-else>
            <h4 class="mt-3 text-sm font-semibold">Gempa dalam {{ RADIUS_KM }} km, 7 hari terakhir</h4>
            <ul v-if="quakes.length" class="mt-1 divide-y divide-line">
                <li v-for="q in quakes" :key="q.f.id">
                    <Link :href="`/berita/${q.f.id}`" class="grid grid-cols-[2.8rem_1fr] items-center gap-x-3 py-2 hover:bg-mist">
                        <span class="mag text-3xl" :style="{ color: magnitudeInk(q.f.properties.magnitude) }">{{ formatMagnitude(q.f.properties.magnitude) }}</span>
                        <span class="text-sm"><span class="block truncate font-semibold">{{ q.f.properties.location }}</span><span class="text-xs text-muted">{{ nf.format(q.d) }} km dari Anda, {{ formatAgo(q.f.properties.occurred_at) }}</span></span>
                    </Link>
                </li>
            </ul>
            <p v-else class="mt-1 text-sm text-muted">Tidak ada gempa tercatat dalam {{ RADIUS_KM }} km dari Anda selama 7 hari terakhir.</p>

            <h4 class="mt-4 text-sm font-semibold">Posko pengungsian terdekat</h4>
            <ul v-if="camps.length" class="mt-1 divide-y divide-line">
                <li v-for="c in camps" :key="c.f.id" class="flex items-baseline justify-between gap-3 py-2 text-sm">
                    <span class="min-w-0 truncate font-semibold">{{ c.f.properties.name }}</span>
                    <span class="shrink-0 text-xs text-muted">{{ nf.format(c.d) }} km, {{ c.f.properties.current_occupants }} dari {{ c.f.properties.capacity_people }} terisi</span>
                </li>
            </ul>
            <p v-else class="mt-1 text-sm text-muted">Belum ada posko aktif yang tercatat.</p>
            <p class="mt-3 text-xs text-muted">Jarak garis lurus, bukan rute jalan. Ikuti arahan petugas setempat saat evakuasi.</p>
            <button type="button" class="mt-2 text-sm font-semibold underline underline-offset-2" @click="locate">Cek ulang posisi saya</button>
        </template>
    </div>
</template>
