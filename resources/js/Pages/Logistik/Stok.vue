<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import Dialog from '../../components/Dialog.vue';
import { useToast } from '../../composables/useToast';
import Layout from '../../Layouts/LogistikLayout.vue';
import { api, errorMessage } from '../../lib/api';
import { TONE_CLASS, expiryInfo, formatQty } from '../../lib/logistik';

const { toast } = useToast();

const warehouses = ref([]);
const current = ref(null);
const rows = ref([]);
const canManage = ref(false);
const loadingList = ref(true);
const loadingStock = ref(false);
const error = ref(null);

async function loadWarehouses() {
    loadingList.value = true;

    try {
        warehouses.value = (await api('/warehouses')).data;
        // Utamakan gudang yang dikelola pengguna.
        const first = warehouses.value.find((w) => w.can_manage) ?? warehouses.value[0];
        if (first) await select(first);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loadingList.value = false;
    }
}

async function select(warehouse) {
    current.value = warehouse;
    loadingStock.value = true;
    error.value = null;

    try {
        const res = await api(`/warehouses/${warehouse.id}/stock`);
        rows.value = res.data;
        canManage.value = res.can_manage;
    } catch (e) {
        rows.value = [];
        error.value = errorMessage(e);
    } finally {
        loadingStock.value = false;
    }
}

// Total stok bebas per barang, supaya terlihat sekilas barang apa yang menipis.
const totals = computed(() => {
    const map = new Map();

    for (const r of rows.value) {
        const key = r.item.sku;
        const t = map.get(key) ?? { ...r.item, free: 0 };
        t.free += r.quantity_free;
        map.set(key, t);
    }

    return [...map.values()].sort((a, b) => a.free - b.free);
});

// ---- Tambah stok -----------------------------------------------------------------------------
const items = ref([]);
const dialog = reactive({ open: false, busy: false, error: null });
const form = reactive({ item_id: '', batch_number: '', quantity: '', expiry_date: '' });

const selectedItem = computed(() => items.value.find((i) => i.id === form.item_id));
const tomorrow = computed(() => new Date(Date.now() + 86_400_000).toISOString().slice(0, 10));

async function openAdd() {
    Object.assign(form, { item_id: '', batch_number: '', quantity: '', expiry_date: '' });
    Object.assign(dialog, { open: true, busy: false, error: null });

    if (!items.value.length) {
        try {
            items.value = (await api('/items')).data;
        } catch (e) {
            dialog.error = errorMessage(e);
        }
    }
}

async function submitAdd() {
    dialog.busy = true;
    dialog.error = null;

    try {
        await api(`/warehouses/${current.value.id}/stock`, {
            method: 'POST',
            body: {
                item_id: form.item_id,
                batch_number: form.batch_number,
                quantity: String(form.quantity),
                expiry_date: form.expiry_date || null,
            },
        });
        toast(`Stok ${selectedItem.value.name} batch ${form.batch_number} dicatat di ${current.value.name}.`);
        dialog.open = false;
        await select(current.value);
    } catch (e) {
        dialog.error = errorMessage(e);
    } finally {
        dialog.busy = false;
    }
}

onMounted(loadWarehouses);
</script>

<template>
    <Head title="Stok gudang" />

    <Layout title="Stok gudang">
        <p v-if="loadingList" class="mt-6 text-sm text-muted">Memuat gudang…</p>

        <div v-else-if="!warehouses.length && !error" class="mt-6 border-t border-line py-10 text-sm text-muted">
            <p class="font-medium text-ink">Belum ada gudang yang bisa Anda lihat.</p>
            <p class="mt-1">Hubungi administrator untuk ditetapkan sebagai pengelola sebuah gudang.</p>
        </div>

        <div v-else class="mt-5 xl:grid xl:grid-cols-[13rem_minmax(0,1fr)] xl:gap-8">
            <nav aria-label="Daftar gudang" class="-mx-1 flex gap-1 overflow-x-auto pb-2 xl:mx-0 xl:flex-col xl:overflow-visible xl:pb-0">
                <button
                    v-for="w in warehouses"
                    :key="w.id"
                    type="button"
                    class="shrink-0 rounded-md px-3 py-2 text-left text-sm"
                    :class="current?.id === w.id ? 'bg-ink font-medium text-white' : 'hover:bg-surface'"
                    :aria-current="current?.id === w.id ? 'true' : undefined"
                    @click="select(w)"
                >
                    <span class="block">{{ w.name }}</span>
                    <span class="block text-xs" :class="current?.id === w.id ? 'text-white/65' : 'text-muted'">
                        {{ w.batches }} batch<template v-if="w.can_manage">, Anda pengelola</template>
                    </span>
                </button>
            </nav>

            <section class="mt-4 min-w-0 xl:mt-0">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">{{ current?.name }}</h2>
                    <button v-if="canManage" type="button" class="rounded-md bg-ink px-4 py-2 text-sm font-medium text-white" @click="openAdd">Tambah stok</button>
                    <p v-else-if="current" class="text-xs text-muted">Hanya pengelola gudang ini yang bisa menambah stok.</p>
                </div>

                <p v-if="error" class="mt-3 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ error }}</p>
                <p v-if="loadingStock" class="mt-4 text-sm text-muted">Memuat stok…</p>

                <template v-else-if="rows.length">
                    <ul class="mt-4 flex flex-wrap gap-x-8 gap-y-3 border-y border-line py-4">
                        <li v-for="t in totals" :key="t.sku">
                            <p class="text-xs text-muted">{{ t.name }}</p>
                            <p class="mag mt-1 text-2xl" :class="t.free <= 0 ? 'text-quake-3' : ''">
                                {{ formatQty(t.free) }} <span class="font-sans text-xs font-normal text-muted">{{ t.unit }} bebas</span>
                            </p>
                        </li>
                    </ul>

                    <div class="mt-4 overflow-x-auto bg-surface">
                        <table class="w-full min-w-[34rem] text-sm">
                            <thead class="border-b border-line text-left text-xs text-muted">
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">Barang</th>
                                    <th class="px-4 py-2.5 font-medium">Batch</th>
                                    <th class="px-4 py-2.5 font-medium">Kedaluwarsa</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Tersedia</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Direservasi</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Bebas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in rows" :key="r.id" class="border-b border-line last:border-b-0">
                                    <td class="px-4 py-2.5 font-medium">{{ r.item.name }}</td>
                                    <td class="px-4 py-2.5 text-muted">{{ r.batch_number }}</td>
                                    <td class="px-4 py-2.5" :class="TONE_CLASS[expiryInfo(r.expiry_date).tone]">{{ expiryInfo(r.expiry_date).text }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ formatQty(r.quantity_available) }}</td>
                                    <td class="px-4 py-2.5 text-right text-muted">{{ formatQty(r.quantity_reserved) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold">{{ formatQty(r.quantity_free) }} <span class="text-xs font-normal text-muted">{{ r.item.unit }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <div v-else-if="current && !error" class="mt-4 border-t border-line py-8 text-sm text-muted">
                    <p class="font-medium text-ink">Gudang ini belum punya stok.</p>
                    <p v-if="canManage" class="mt-1">Gunakan tombol Tambah stok untuk mencatat barang yang masuk.</p>
                </div>
            </section>
        </div>

        <Dialog :open="dialog.open" :title="`Tambah stok di ${current?.name ?? ''}`" @close="dialog.open = false">
            <form id="form-stock" class="space-y-4 px-5 py-4" @submit.prevent="submitAdd">
                <div>
                    <label for="item" class="block text-sm font-medium">Barang</label>
                    <select id="item" v-model="form.item_id" required class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2 text-sm">
                        <option value="" disabled>Pilih barang</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.name }} ({{ i.unit }}), {{ i.category }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="batch" class="block text-sm font-medium">Nomor batch</label>
                        <input id="batch" v-model="form.batch_number" type="text" maxlength="100" required class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label for="qty" class="block text-sm font-medium">Jumlah<template v-if="selectedItem"> ({{ selectedItem.unit }})</template></label>
                        <input id="qty" v-model="form.quantity" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm" />
                    </div>
                </div>
                <div>
                    <label for="exp" class="block text-sm font-medium">
                        Tanggal kedaluwarsa<template v-if="selectedItem?.is_perishable"> (disarankan diisi)</template><template v-else> (opsional)</template>
                    </label>
                    <input id="exp" v-model="form.expiry_date" type="date" :min="tomorrow" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm" />
                    <p class="mt-1 text-xs text-muted">Batch dengan nomor yang sama harus punya tanggal yang sama; jumlahnya akan ditambahkan ke batch itu.</p>
                </div>
                <p v-if="dialog.error" class="border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ dialog.error }}</p>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2 text-sm hover:bg-mist" @click="dialog.open = false">Batal</button>
                <button type="submit" form="form-stock" class="rounded-md bg-ink px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="dialog.busy">
                    {{ dialog.busy ? 'Menyimpan…' : 'Catat stok' }}
                </button>
            </template>
        </Dialog>
    </Layout>
</template>
