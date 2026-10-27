<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Dialog from '../../components/Dialog.vue';
import { useToast } from '../../composables/useToast';
import Layout from '../../Layouts/LogistikLayout.vue';
import { ApiError, api, errorMessage } from '../../lib/api';
import { DISPATCH_STATUS, TONE_CLASS, formatDate, formatQty } from '../../lib/logistik';
import { formatAgo, formatWib } from '../../lib/quake';

const page = usePage();
const { toast } = useToast();

const canOverride = computed(() => page.props.auth.user.can['dispatch.override-geofence']);

const GROUPS = [
    { key: 'aktif', label: 'Aktif', statuses: ['draft', 'dispatched', 'in_transit'] },
    { key: 'diterima', label: 'Diterima', statuses: ['received'] },
    { key: 'batal', label: 'Dibatalkan', statuses: ['cancelled'] },
    { key: 'semua', label: 'Semua', statuses: null },
];

const dispatches = ref([]);
const loading = ref(true);
const error = ref(null);
const group = ref('aktif');
const expanded = ref(null);
const now = ref(Date.now());

const inGroup = (d, g) => !g.statuses || g.statuses.includes(d.status);
const counts = computed(() => Object.fromEntries(GROUPS.map((g) => [g.key, dispatches.value.filter((d) => inGroup(d, g)).length])));
const visible = computed(() => dispatches.value.filter((d) => inGroup(d, GROUPS.find((g) => g.key === group.value))));

async function load() {
    loading.value = true;
    error.value = null;

    try {
        dispatches.value = (await api('/dispatches')).data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

function itemsSummary(d) {
    const first = d.items.slice(0, 2).map((i) => `${i.item.name} ${formatQty(i.quantity_dispatched)} ${i.item.unit}`);
    const more = d.items.length - 2;

    return first.join(', ') + (more > 0 ? `, +${more} lainnya` : '');
}

function when(d) {
    if (d.status === 'received') return `Tiba ${formatWib(d.arrival_time)}`;
    if (d.departure_time) return `Berangkat ${formatAgo(d.departure_time, now.value)}`;

    return 'Belum berangkat';
}

// ---- Dialog aksi ----------------------------------------------------------------------------
const dialog = reactive({ type: null, dispatch: null, busy: false, error: null });

function open(type, dispatch) {
    Object.assign(dialog, { type, dispatch, busy: false, error: null });

    if (type === 'send') Object.assign(sendForm, { driver_name: '', vehicle_plate_number: '' });
    if (type === 'cancel') cancelForm.reason = '';
    if (type === 'receive') resetReceive(dispatch);
}

function close() {
    dialog.type = null;
}

async function submit(path, body, success) {
    dialog.busy = true;
    dialog.error = null;

    try {
        await api(path, { method: 'POST', body });
        toast(success);
        close();
        await load();
    } catch (e) {
        dialog.error = errorMessage(e);

        if (e instanceof ApiError && e.status === 409) {
            // Status sudah berubah (mis. diproses orang lain): tampilkan data terbaru.
            await load();
        }
    } finally {
        dialog.busy = false;
    }
}

const sendForm = reactive({ driver_name: '', vehicle_plate_number: '' });
const cancelForm = reactive({ reason: '' });

function doSend() {
    submit(`/dispatches/${dialog.dispatch.id}/send`, { ...sendForm }, `${dialog.dispatch.code} diberangkatkan. Stok gudang sudah dikurangi.`);
}

function doCancel() {
    submit(`/dispatches/${dialog.dispatch.id}/cancel`, { reason: cancelForm.reason }, `${dialog.dispatch.code} dibatalkan. Reservasi stok dilepas.`);
}

// ---- Terima atas nama posko ------------------------------------------------------------------
const receiveForm = reactive({ latitude: '', longitude: '', accuracy: '', notes: '', override: '', quantities: {} });
const locating = ref(false);
const locationNote = ref('');

function resetReceive(dispatch) {
    Object.assign(receiveForm, {
        latitude: '', longitude: '', accuracy: '', notes: '', override: '',
        quantities: Object.fromEntries(dispatch.items.map((i) => [i.id, String(i.quantity_dispatched)])),
    });
    locationNote.value = '';
}

function useDeviceLocation() {
    if (!navigator.geolocation) {
        locationNote.value = 'Peramban ini tidak mendukung lokasi. Isi koordinat secara manual.';

        return;
    }

    locating.value = true;
    locationNote.value = '';

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            receiveForm.latitude = pos.coords.latitude.toFixed(6);
            receiveForm.longitude = pos.coords.longitude.toFixed(6);
            receiveForm.accuracy = Math.round(pos.coords.accuracy);
            locationNote.value = `Lokasi perangkat terbaca, akurasi sekitar ${Math.round(pos.coords.accuracy)} m.`;
            locating.value = false;
        },
        (err) => {
            locationNote.value = err.code === 1 ? 'Izin lokasi ditolak. Izinkan lokasi di peramban atau isi koordinat manual.' : 'Lokasi tidak bisa dibaca. Isi koordinat secara manual.';
            locating.value = false;
        },
        { enableHighAccuracy: true, timeout: 15000 },
    );
}

const receiveReady = computed(() => receiveForm.latitude !== '' && receiveForm.longitude !== '');

function doReceive() {
    submit(
        `/dispatches/${dialog.dispatch.id}/receive`,
        {
            latitude: Number(receiveForm.latitude),
            longitude: Number(receiveForm.longitude),
            accuracy: receiveForm.accuracy === '' ? null : Number(receiveForm.accuracy),
            received: dialog.dispatch.items.map((i) => ({ dispatch_item_id: i.id, quantity: Number(receiveForm.quantities[i.id] || 0) })),
            notes: receiveForm.notes || null,
            geofence_override_reason: receiveForm.override || null,
        },
        `${dialog.dispatch.code} diterima di ${dialog.dispatch.camp.name}.`,
    );
}

let clock;
onMounted(() => {
    load();
    clock = setInterval(() => (now.value = Date.now()), 60000);
});
onBeforeUnmount(() => clearInterval(clock));
</script>

<template>
    <Head title="Pengiriman" />

    <Layout title="Pengiriman bantuan">
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex overflow-hidden rounded-md border border-line" role="group" aria-label="Saring pengiriman">
                <button
                    v-for="g in GROUPS"
                    :key="g.key"
                    type="button"
                    :aria-pressed="group === g.key"
                    class="border-r border-line px-3.5 py-1.5 text-sm last:border-r-0"
                    :class="group === g.key ? 'bg-ink font-medium text-white' : 'bg-surface hover:bg-mist'"
                    @click="group = g.key"
                >
                    {{ g.label }} <span :class="group === g.key ? 'text-white/70' : 'text-muted'">{{ counts[g.key] }}</span>
                </button>
            </div>
            <button type="button" class="text-sm text-muted underline underline-offset-2 hover:text-ink" @click="load">Muat ulang</button>
        </div>

        <div v-if="error" class="mt-4 rounded-md border-l-4 border-quake-3 bg-quake-3/5 px-4 py-3 text-sm" role="alert">
            {{ error }} <button type="button" class="ml-2 font-medium underline underline-offset-2" @click="load">Coba lagi</button>
        </div>

        <p v-if="loading && !dispatches.length" class="mt-6 text-sm text-muted">Memuat pengiriman…</p>

        <ul v-if="visible.length" class="mt-3 border-t border-line bg-surface">
            <li v-for="d in visible" :key="d.id" class="border-b border-line">
                <div class="grid gap-x-4 gap-y-2 px-5 py-3.5 md:grid-cols-[minmax(0,1.6fr)_9rem_auto] md:items-center">
                    <button type="button" class="min-w-0 text-left" :aria-expanded="expanded === d.id" @click="expanded = expanded === d.id ? null : d.id">
                        <span class="block text-sm font-semibold">{{ d.code }}</span>
                        <span class="block truncate text-sm text-muted">{{ d.warehouse.name }} ke {{ d.camp.name }}</span>
                        <span class="mt-0.5 block truncate text-xs text-muted">{{ itemsSummary(d) }}</span>
                    </button>

                    <div class="text-sm">
                        <p class="font-medium" :class="TONE_CLASS[DISPATCH_STATUS[d.status].tone]">{{ DISPATCH_STATUS[d.status].label }}</p>
                        <p class="text-xs text-muted">{{ when(d) }}</p>
                    </div>

                    <div class="flex flex-wrap gap-2 md:justify-end">
                        <button v-if="d.can?.send && d.status === 'draft'" type="button" class="rounded-md bg-ink px-3 py-1.5 text-sm font-medium text-white" @click="open('send', d)">Berangkatkan</button>
                        <button v-if="d.can?.cancel && d.status === 'draft'" type="button" class="rounded-md border border-line px-3 py-1.5 text-sm hover:bg-mist" @click="open('cancel', d)">Batalkan</button>
                        <button
                            v-if="d.can?.receive && (d.status === 'dispatched' || d.status === 'in_transit')"
                            type="button"
                            class="rounded-md bg-ink px-3 py-1.5 text-sm font-medium text-white"
                            @click="open('receive', d)"
                        >
                            Terima atas nama posko
                        </button>
                    </div>
                </div>

                <div v-if="expanded === d.id" class="border-t border-line bg-mist/60 px-5 py-4">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted">
                            <tr>
                                <th class="pb-2 font-medium">Barang</th>
                                <th class="pb-2 font-medium">Batch</th>
                                <th class="pb-2 font-medium">Kedaluwarsa</th>
                                <th class="pb-2 text-right font-medium">Dikirim</th>
                                <th class="pb-2 text-right font-medium">Diterima</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in d.items" :key="i.id" class="border-t border-line">
                                <td class="py-2">{{ i.item.name }}</td>
                                <td class="py-2 text-muted">{{ i.batch_number }}</td>
                                <td class="py-2 text-muted">{{ formatDate(i.expiry_date) }}</td>
                                <td class="py-2 text-right">{{ formatQty(i.quantity_dispatched) }} {{ i.item.unit }}</td>
                                <td class="py-2 text-right" :class="i.quantity_received !== null && i.quantity_received < i.quantity_dispatched ? 'font-medium text-quake-3' : ''">
                                    {{ i.quantity_received === null ? '–' : formatQty(i.quantity_received) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="d.driver_name || d.vehicle_plate_number" class="mt-3 text-xs text-muted">Sopir {{ d.driver_name || '–' }}, kendaraan {{ d.vehicle_plate_number || '–' }}.</p>
                    <p v-if="d.receiver_notes" class="mt-1 text-xs text-muted">Catatan penerima: {{ d.receiver_notes }}</p>
                </div>
            </li>
        </ul>

        <div v-else-if="!loading && !error" class="mt-3 border-t border-line px-1 py-10 text-sm text-muted">
            <p class="font-medium text-ink">Tidak ada pengiriman di kelompok ini.</p>
            <p class="mt-1">Pengiriman dibuat dari halaman Permintaan, lewat saran alokasi stok.</p>
        </div>

        <!-- Berangkatkan -->
        <Dialog :open="dialog.type === 'send'" title="Berangkatkan pengiriman" @close="close">
            <form id="form-send" class="space-y-4 px-5 py-4" @submit.prevent="doSend">
                <p class="text-sm text-muted">{{ dialog.dispatch?.code }}: stok dikurangi dari gudang saat dikonfirmasi dan tidak bisa dibatalkan lagi.</p>
                <div>
                    <label for="driver" class="block text-sm font-medium">Nama sopir (opsional)</label>
                    <input id="driver" v-model="sendForm.driver_name" type="text" maxlength="255" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm" />
                </div>
                <div>
                    <label for="plate" class="block text-sm font-medium">Nomor kendaraan (opsional)</label>
                    <input id="plate" v-model="sendForm.vehicle_plate_number" type="text" maxlength="50" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm" />
                </div>
                <p v-if="dialog.error" class="border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ dialog.error }}</p>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2 text-sm hover:bg-mist" @click="close">Kembali</button>
                <button type="submit" form="form-send" class="rounded-md bg-ink px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="dialog.busy">
                    {{ dialog.busy ? 'Memproses…' : 'Berangkatkan' }}
                </button>
            </template>
        </Dialog>

        <!-- Batalkan -->
        <Dialog :open="dialog.type === 'cancel'" title="Batalkan pengiriman" @close="close">
            <form id="form-cancel" class="space-y-4 px-5 py-4" @submit.prevent="doCancel">
                <p class="text-sm text-muted">{{ dialog.dispatch?.code }}: reservasi stok dilepas dan stok kembali bisa dialokasikan.</p>
                <div>
                    <label for="reason" class="block text-sm font-medium">Alasan pembatalan</label>
                    <textarea id="reason" v-model="cancelForm.reason" rows="3" minlength="3" maxlength="500" required class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm" />
                </div>
                <p v-if="dialog.error" class="border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ dialog.error }}</p>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2 text-sm hover:bg-mist" @click="close">Kembali</button>
                <button type="submit" form="form-cancel" class="rounded-md bg-quake-4 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="dialog.busy">
                    {{ dialog.busy ? 'Memproses…' : 'Batalkan pengiriman' }}
                </button>
            </template>
        </Dialog>

        <!-- Terima atas nama posko -->
        <Dialog :open="dialog.type === 'receive'" title="Terima atas nama posko" @close="close">
            <form v-if="dialog.dispatch" id="form-receive" class="space-y-5 px-5 py-4" @submit.prevent="doReceive">
                <p class="text-sm text-muted">
                    {{ dialog.dispatch.code }} ke {{ dialog.dispatch.camp.name }}. Serah terima sah bila lokasi Anda dalam 500 m dari posko; di luar itu perlu alasan tertulis.
                </p>

                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">Lokasi saat menerima</legend>
                    <button type="button" class="rounded-md border border-line px-3 py-1.5 text-sm hover:bg-mist disabled:opacity-60" :disabled="locating" @click="useDeviceLocation">
                        {{ locating ? 'Membaca lokasi…' : 'Pakai lokasi perangkat saya' }}
                    </button>
                    <p v-if="locationNote" class="text-xs text-muted" role="status">{{ locationNote }}</p>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label for="lat" class="block text-xs text-muted">Lintang</label>
                            <input id="lat" v-model="receiveForm.latitude" type="number" step="any" min="-90" max="90" required class="mt-0.5 w-full rounded-md border border-line px-2 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label for="lng" class="block text-xs text-muted">Bujur</label>
                            <input id="lng" v-model="receiveForm.longitude" type="number" step="any" min="-180" max="180" required class="mt-0.5 w-full rounded-md border border-line px-2 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label for="acc" class="block text-xs text-muted">Akurasi (m)</label>
                            <input id="acc" v-model="receiveForm.accuracy" type="number" step="any" min="0" class="mt-0.5 w-full rounded-md border border-line px-2 py-1.5 text-sm" />
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-sm font-medium">Jumlah diterima</legend>
                    <ul class="mt-2 space-y-2">
                        <li v-for="i in dialog.dispatch.items" :key="i.id" class="flex items-center justify-between gap-3 text-sm">
                            <label :for="`q-${i.id}`" class="min-w-0 flex-1">
                                {{ i.item.name }}
                                <span class="block text-xs text-muted">dikirim {{ formatQty(i.quantity_dispatched) }} {{ i.item.unit }}</span>
                            </label>
                            <input
                                :id="`q-${i.id}`"
                                v-model="receiveForm.quantities[i.id]"
                                type="number"
                                step="0.01"
                                min="0"
                                :max="i.quantity_dispatched"
                                required
                                class="w-28 rounded-md border border-line px-2 py-1.5 text-right text-sm"
                            />
                        </li>
                    </ul>
                </fieldset>

                <div>
                    <label for="notes" class="block text-sm font-medium">Catatan (opsional)</label>
                    <textarea id="notes" v-model="receiveForm.notes" rows="2" maxlength="1000" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm" />
                </div>

                <div v-if="canOverride">
                    <label for="override" class="block text-sm font-medium">Alasan melewati batas 500 m</label>
                    <textarea
                        id="override"
                        v-model="receiveForm.override"
                        rows="2"
                        maxlength="500"
                        placeholder="Isi hanya bila lokasi Anda memang di luar posko, misalnya posko pindah tenda."
                        class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                    />
                    <p class="mt-1 text-xs text-muted">Alasan ini dicatat permanen di jejak audit bersama koordinat Anda.</p>
                </div>

                <p v-if="dialog.error" class="border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ dialog.error }}</p>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2 text-sm hover:bg-mist" @click="close">Kembali</button>
                <button type="submit" form="form-receive" class="rounded-md bg-ink px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="dialog.busy || !receiveReady">
                    {{ dialog.busy ? 'Memproses…' : 'Konfirmasi penerimaan' }}
                </button>
            </template>
        </Dialog>
    </Layout>
</template>
