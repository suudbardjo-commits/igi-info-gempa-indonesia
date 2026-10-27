<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Dialog from '../../components/Dialog.vue';
import { useToast } from '../../composables/useToast';
import Layout from '../../Layouts/LogistikLayout.vue';
import { api, errorMessage } from '../../lib/api';
import { DEMAND_STATUS, URGENCY, formatDate, formatKm, formatQty } from '../../lib/logistik';
import { formatAgo } from '../../lib/quake';

const page = usePage();
const { toast } = useToast();

const canDispatch = computed(() => page.props.auth.user.can['dispatch.create']);

const FILTERS = [
    { value: 'open', label: 'Belum terpenuhi' },
    { value: 'fulfilled', label: 'Terpenuhi' },
    { value: '', label: 'Semua' },
];

const summary = ref(null);
const filter = ref('open');
const demands = ref([]);
const loading = ref(true);
const error = ref(null);
const now = ref(Date.now());

const selected = ref(null);
const plan = ref(null);
const planLoading = ref(false);
const planError = ref(null);
const creating = ref(false);
const created = ref(null);

const isOpen = (d) => d.status === 'pending' || d.status === 'partial';

async function loadSummary() {
    try {
        summary.value = await api('/logistics/summary');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}

async function loadDemands() {
    loading.value = true;
    error.value = null;

    try {
        demands.value = (await api('/demands', { query: { status: filter.value } })).data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

function setFilter(value) {
    filter.value = value;
    loadDemands();
}

async function loadPlan() {
    planLoading.value = true;
    planError.value = null;

    try {
        plan.value = await api(`/demands/${selected.value.id}/allocation-plan`);
    } catch (e) {
        planError.value = errorMessage(e);
    } finally {
        planLoading.value = false;
    }
}

async function openDemand(demand) {
    selected.value = demand;
    plan.value = null;
    created.value = null;

    if (canDispatch.value && isOpen(demand)) await loadPlan();
}

async function createDispatches() {
    creating.value = true;

    try {
        const result = await api(`/demands/${selected.value.id}/dispatches`, { method: 'POST' });
        created.value = result;
        toast(`${result.dispatches.length} pengiriman draft dibuat dan stoknya direservasi.`);
        loadDemands();
        loadSummary();
    } catch (e) {
        toast(errorMessage(e), 'error');
        // Stok bisa berubah sejak rencana dihitung; tampilkan rencana terbaru.
        await loadPlan();
    } finally {
        creating.value = false;
    }
}

const hasStock = computed(() => plan.value && plan.value.sources.length > 0);
const unit = computed(() => selected.value?.item?.unit ?? '');

let clock;
onMounted(() => {
    loadSummary();
    loadDemands();
    clock = setInterval(() => (now.value = Date.now()), 60000);
});
onBeforeUnmount(() => clearInterval(clock));
</script>

<template>
    <Head title="Permintaan posko" />

    <Layout title="Permintaan posko">
        <!-- Ringkasan: satu baris angka, bukan kartu -->
        <dl class="mt-5 grid grid-cols-2 border-y border-line md:grid-cols-4">
            <div class="border-r border-line px-1 py-4 md:px-5 md:first:pl-0">
                <dt class="text-xs text-muted">Permintaan belum terpenuhi</dt>
                <dd class="mag mt-1.5 text-3xl">{{ summary ? formatQty(summary.demands_open) : '–' }}</dd>
            </div>
            <div class="px-4 py-4 md:border-r md:border-line md:px-5">
                <dt class="text-xs text-muted">Berstatus kritis</dt>
                <dd class="mag mt-1.5 text-3xl" :class="summary?.demands_critical ? 'text-quake-4' : ''">{{ summary ? formatQty(summary.demands_critical) : '–' }}</dd>
            </div>
            <div class="border-r border-t border-line px-1 py-4 md:border-t-0 md:px-5">
                <dt class="text-xs text-muted">Pengiriman berjalan</dt>
                <dd class="mag mt-1.5 text-3xl">{{ summary ? formatQty(summary.dispatches_active) : '–' }}</dd>
            </div>
            <div class="border-t border-line px-4 py-4 md:border-t-0 md:px-5">
                <dt class="text-xs text-muted">Batch segera kedaluwarsa</dt>
                <dd class="mag mt-1.5 text-3xl" :class="summary?.batches_expiring || summary?.batches_expired ? 'text-quake-2' : ''">
                    {{ summary ? formatQty(summary.batches_expiring + summary.batches_expired) : '–' }}
                </dd>
                <dd v-if="summary?.batches_expired" class="mt-1 text-xs text-quake-3">{{ summary.batches_expired }} sudah lewat tanggalnya</dd>
            </div>
        </dl>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex overflow-hidden rounded-md border border-line" role="group" aria-label="Saring permintaan">
                <button
                    v-for="f in FILTERS"
                    :key="f.value"
                    type="button"
                    :aria-pressed="filter === f.value"
                    class="border-r border-line px-3.5 py-1.5 text-sm last:border-r-0"
                    :class="filter === f.value ? 'bg-ink font-medium text-white' : 'bg-surface hover:bg-mist'"
                    @click="setFilter(f.value)"
                >
                    {{ f.label }}
                </button>
            </div>
            <p class="text-sm text-muted" aria-live="polite">{{ loading ? 'Memuat…' : `${demands.length} permintaan` }}</p>
        </div>

        <div v-if="error" class="mt-4 rounded-md border-l-4 border-quake-3 bg-quake-3/5 px-4 py-3 text-sm" role="alert">
            {{ error }}
            <button type="button" class="ml-2 font-medium underline underline-offset-2" @click="loadDemands">Coba lagi</button>
        </div>

        <ul v-if="demands.length" class="mt-3 border-t border-line bg-surface">
            <li v-for="d in demands" :key="d.id" class="relative border-b border-line">
                <span class="absolute inset-y-0 left-0 w-1" :style="{ background: URGENCY[d.urgency_level].color }" aria-hidden="true" />
                <button
                    type="button"
                    class="grid w-full gap-x-4 gap-y-1 px-5 py-3.5 text-left hover:bg-mist md:grid-cols-[minmax(0,1.7fr)_7rem_8rem_9rem] md:items-center"
                    @click="openDemand(d)"
                >
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold">{{ d.item.name }}</span>
                        <span class="block truncate text-sm text-muted">{{ d.camp.name }}</span>
                        <span class="mt-0.5 block text-xs text-muted">Urgensi {{ URGENCY[d.urgency_level].label.toLowerCase() }}</span>
                    </span>

                    <span>
                        <span class="mag text-2xl">{{ formatQty(d.quantity_remaining) }}</span>
                        <span class="ml-1 text-xs text-muted">{{ d.item.unit }}</span>
                        <span class="block text-xs text-muted">dari {{ formatQty(d.quantity_requested) }}</span>
                    </span>

                    <span class="text-sm text-muted">
                        <template v-if="isOpen(d)">Menunggu {{ formatAgo(d.created_at, now).replace(' yang lalu', '') }}</template>
                        <template v-else>{{ DEMAND_STATUS[d.status] }}</template>
                    </span>

                    <span class="text-sm">
                        <template v-if="!isOpen(d)"><span class="text-muted">–</span></template>
                        <span v-else-if="!d.suggestion" class="text-muted">Belum dihitung</span>
                        <span v-else-if="d.suggestion.complete" class="font-medium text-camp">Stok cukup</span>
                        <span v-else class="font-medium text-quake-3">Kurang {{ formatQty(d.suggestion.shortfall) }} {{ d.item.unit }}</span>
                    </span>
                </button>
            </li>
        </ul>

        <div v-else-if="!loading && !error" class="mt-3 border-t border-line px-1 py-10 text-sm text-muted">
            <p class="font-medium text-ink">Tidak ada permintaan di sini.</p>
            <p class="mt-1">Permintaan baru dari koordinator posko akan muncul otomatis saat halaman dimuat ulang.</p>
        </div>

        <!-- Panel rincian dan saran alokasi -->
        <Dialog :open="!!selected" variant="side" :title="selected ? `${selected.item.name} untuk ${selected.camp.name}` : ''" @close="selected = null">
            <div v-if="selected">
                <dl class="grid grid-cols-3 gap-x-4 border-b border-line px-5 py-4">
                    <div>
                        <dt class="text-xs text-muted">Diminta</dt>
                        <dd class="mag mt-1 text-2xl">{{ formatQty(selected.quantity_requested) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Terpenuhi</dt>
                        <dd class="mag mt-1 text-2xl text-camp">{{ formatQty(selected.quantity_fulfilled) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Sisa</dt>
                        <dd class="mag mt-1 text-2xl">{{ formatQty(selected.quantity_remaining) }}</dd>
                    </div>
                    <div class="col-span-3 mt-3 text-sm">
                        <span class="inline-block size-2.5 rounded-full align-baseline" :style="{ background: URGENCY[selected.urgency_level].color }" />
                        Urgensi {{ URGENCY[selected.urgency_level].label.toLowerCase() }}, {{ DEMAND_STATUS[selected.status].toLowerCase() }}.
                        <span v-if="selected.notes" class="mt-1 block text-muted">Catatan koordinator: {{ selected.notes }}</span>
                    </div>
                </dl>

                <!-- Hasil setelah pengiriman dibuat -->
                <section v-if="created" class="px-5 py-5">
                    <p class="font-semibold text-camp">{{ created.dispatches.length }} pengiriman draft dibuat</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li v-for="x in created.dispatches" :key="x.id" class="border-b border-line pb-2">
                            <span class="font-medium">{{ x.code }}</span>
                            <span class="block text-muted">{{ x.warehouse.name }} ke {{ x.camp.name }}</span>
                        </li>
                    </ul>
                    <p v-if="!created.complete" class="mt-3 border-l-4 border-quake-2 bg-quake-2/5 px-3 py-2 text-sm">
                        Masih kurang {{ formatQty(created.shortfall) }} {{ unit }} karena stok gudang belum cukup.
                    </p>
                    <Link href="/logistik/pengiriman" class="mt-4 inline-block rounded-md bg-ink px-4 py-2 text-sm font-medium text-white">Buka daftar pengiriman</Link>
                </section>

                <!-- Saran alokasi -->
                <section v-else-if="isOpen(selected)" class="px-5 py-5">
                    <h3 class="text-sm font-semibold">Saran alokasi stok</h3>

                    <p v-if="!canDispatch" class="mt-2 text-sm text-muted">Alokasi stok dihitung dan dijalankan oleh pengelola gudang. Anda bisa memantau statusnya di halaman Pengiriman.</p>
                    <p v-else-if="planLoading" class="mt-2 text-sm text-muted">Menghitung gudang terdekat…</p>
                    <p v-else-if="planError" class="mt-2 text-sm text-quake-3" role="alert">{{ planError }}</p>

                    <template v-else-if="plan">
                        <p v-if="!hasStock" class="mt-3 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm">
                            Belum ada stok bebas untuk barang ini di gudang mana pun. Tambahkan stok di halaman Stok gudang.
                        </p>

                        <ol v-else class="mt-3 divide-y divide-line border-y border-line">
                            <li v-for="s in plan.sources" :key="s.warehouse_id" class="py-3">
                                <div class="flex items-baseline justify-between gap-3">
                                    <p class="text-sm font-semibold">{{ s.warehouse_name }}</p>
                                    <p class="shrink-0 text-sm text-muted">{{ formatKm(s.distance_km) }}</p>
                                </div>
                                <p class="mag mt-1 text-2xl">{{ formatQty(s.quantity) }} <span class="font-sans text-xs font-normal text-muted">{{ unit }}</span></p>
                                <ul class="mt-2 space-y-1 text-xs text-muted">
                                    <li v-for="l in s.lines" :key="l.inventory_id" class="flex justify-between gap-3">
                                        <span>Batch {{ l.batch_number }}<template v-if="l.expiry_date">, kedaluwarsa {{ formatDate(l.expiry_date) }}</template></span>
                                        <span class="shrink-0">{{ formatQty(l.quantity) }}</span>
                                    </li>
                                </ul>
                            </li>
                        </ol>

                        <p v-if="hasStock && !plan.complete" class="mt-3 border-l-4 border-quake-2 bg-quake-2/5 px-3 py-2 text-sm">
                            Stok gudang belum cukup: kurang {{ formatQty(plan.shortfall) }} {{ unit }}. Pengiriman tetap bisa dibuat untuk bagian yang tersedia.
                        </p>
                        <p class="mt-3 text-xs text-muted">Urutan berdasarkan jarak garis lurus (bukan rute jalan), lalu batch yang paling cepat kedaluwarsa.</p>
                    </template>
                </section>
            </div>

            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2 text-sm hover:bg-mist" @click="selected = null">Tutup</button>
                <button
                    v-if="canDispatch && selected && isOpen(selected) && !created"
                    type="button"
                    class="rounded-md bg-ink px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="creating || planLoading || !hasStock"
                    @click="createDispatches"
                >
                    {{ creating ? 'Membuat…' : 'Buat pengiriman dari saran ini' }}
                </button>
            </template>
        </Dialog>
    </Layout>
</template>
