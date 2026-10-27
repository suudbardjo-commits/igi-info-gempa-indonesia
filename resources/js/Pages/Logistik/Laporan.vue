<script setup>
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import Layout from '../../Layouts/LogistikLayout.vue';
import { api, errorMessage } from '../../lib/api';
import { formatQty } from '../../lib/logistik';
import { formatWib } from '../../lib/quake';

const reports = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const type = ref('');
const loading = ref(true);
const error = ref(null);

const TYPES = [
    { value: '', label: 'Semua' },
    { value: 'occupancy', label: 'Jumlah pengungsi' },
    { value: 'condition', label: 'Kondisi posko' },
];

const SEVERITY = {
    aman: { text: 'Aman', class: 'text-camp' },
    waspada: { text: 'Waspada', class: 'text-quake-2' },
    darurat: { text: 'Darurat', class: 'text-quake-4' },
};

async function load(page = 1) {
    loading.value = true;
    error.value = null;

    try {
        const res = await api('/field-reports', { query: { type: type.value, page } });
        reports.value = res.data;
        meta.value = res.meta;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

function setType(value) {
    type.value = value;
    load(1);
}

/** "Tertahan 3 jam" bila laporan baru sampai ke server jauh setelah dicatat di lapangan. */
function delay(seconds) {
    if (seconds == null || seconds < 300) return null;

    const hours = Math.floor(seconds / 3600);

    return hours >= 1 ? `${hours} jam` : `${Math.round(seconds / 60)} menit`;
}

onMounted(() => load());
</script>

<template>
    <Head title="Laporan lapangan" />

    <Layout title="Laporan lapangan">
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Laporan dari koordinator dan relawan di posko. Laporan yang dicatat saat tidak ada sinyal ditandai "tertahan", dan waktunya
            adalah waktu saat dicatat di lapangan, bukan saat sampai ke server.
        </p>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex overflow-hidden rounded-md border border-line" role="group" aria-label="Saring laporan">
                <button
                    v-for="t in TYPES"
                    :key="t.value"
                    type="button"
                    :aria-pressed="type === t.value"
                    class="border-r border-line px-3.5 py-1.5 text-sm last:border-r-0"
                    :class="type === t.value ? 'bg-ink font-medium text-white' : 'bg-surface hover:bg-mist'"
                    @click="setType(t.value)"
                >
                    {{ t.label }}
                </button>
            </div>
            <p class="text-sm text-muted" aria-live="polite">{{ loading ? 'Memuat…' : `${formatQty(meta.total)} laporan` }}</p>
        </div>

        <p v-if="error" class="mt-3 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ error }}</p>

        <ul v-if="reports.length" class="mt-3 border-t border-line bg-surface">
            <li v-for="r in reports" :key="r.id" class="grid gap-x-4 gap-y-1 border-b border-line px-5 py-3.5 md:grid-cols-[minmax(0,1fr)_10rem] md:items-start">
                <div class="min-w-0 text-sm">
                    <p class="font-semibold">{{ r.camp.name }}</p>
                    <p v-if="r.type === 'occupancy'" class="mt-0.5">
                        <span class="mag text-xl">{{ formatQty(r.payload.occupants) }}</span> pengungsi<span v-if="r.payload.note" class="text-muted">, {{ r.payload.note }}</span>
                    </p>
                    <p v-else class="mt-0.5">
                        Kondisi <span class="font-semibold" :class="SEVERITY[r.payload.severity].class">{{ SEVERITY[r.payload.severity].text.toLowerCase() }}</span><span v-if="r.payload.note" class="text-muted">: {{ r.payload.note }}</span>
                    </p>
                    <p class="mt-1 text-xs text-muted">
                        Oleh {{ r.reporter ?? 'tidak diketahui' }}<template v-if="r.location">, lokasi {{ r.location.lat }}, {{ r.location.lng }}<template v-if="r.location.accuracy_m"> (±{{ Math.round(r.location.accuracy_m) }} m)</template></template>
                    </p>
                </div>
                <div class="text-xs text-muted md:text-right">
                    <p>{{ formatWib(r.recorded_at) }}</p>
                    <p v-if="delay(r.delay_seconds)" class="mt-0.5 font-medium text-quake-2">Tertahan {{ delay(r.delay_seconds) }} sebelum terkirim</p>
                </div>
            </li>
        </ul>

        <div v-else-if="!loading && !error" class="mt-3 border-t border-line py-10 text-sm text-muted">
            <p class="font-medium text-ink">Belum ada laporan lapangan.</p>
            <p class="mt-1">Laporan muncul di sini begitu koordinator atau relawan mengirimnya dari aplikasi Lapangan.</p>
        </div>

        <nav v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm" aria-label="Halaman">
            <button type="button" class="rounded-md border border-line px-3 py-1.5 hover:bg-mist disabled:opacity-40" :disabled="meta.current_page <= 1 || loading" @click="load(meta.current_page - 1)">Sebelumnya</button>
            <span class="text-muted">Halaman {{ meta.current_page }} dari {{ meta.last_page }}</span>
            <button type="button" class="rounded-md border border-line px-3 py-1.5 hover:bg-mist disabled:opacity-40" :disabled="meta.current_page >= meta.last_page || loading" @click="load(meta.current_page + 1)">Berikutnya</button>
        </nav>
    </Layout>
</template>
