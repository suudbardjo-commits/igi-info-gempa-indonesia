<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { liveQuery } from 'dexie';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import Dialog from '../components/Dialog.vue';
import ToastHost from '../components/ToastHost.vue';
import { useToast } from '../composables/useToast';
import { echo } from '../echo';
import { ApiError, api, errorMessage } from '../lib/api';
import { getPosition } from '../lib/geo';
import { URGENCY, formatKm, formatQty } from '../lib/logistik';
import { cacheGet, cacheSet, discard, enqueue, startSyncEngine, sync, syncNow } from '../lib/outbox';
import { db } from '../lib/offlineDb';
import { formatAgo, formatMagnitude, formatWib, magnitudeColor } from '../lib/quake';
import Icon from '../components/Icon.vue';
import { logout } from '../lib/session';

const page = usePage();
const { toast } = useToast();
const user = computed(() => page.props.auth.user);

// ---- Data dari server, dengan cadangan lokal -------------------------------------------------
const overview = ref(null);
const fromCache = ref(false);
const cachedAt = ref(null);
const loadError = ref(null);
const campId = ref(null);
const now = ref(Date.now());

const camps = computed(() => overview.value?.camps ?? []);
const camp = computed(() => camps.value.find((c) => c.id === campId.value) ?? camps.value[0] ?? null);
const isCoordinator = computed(() => camp.value?.role === 'koordinator');
const incoming = computed(() => (overview.value?.incoming ?? []).filter((d) => d.camp.id === camp.value?.id));
const itemsById = computed(() => Object.fromEntries((overview.value?.items ?? []).map((i) => [i.id, i])));

async function loadOverview() {
    try {
        const data = await api('/field/overview', { redirectOn401: false });
        overview.value = data;
        fromCache.value = false;
        sync.online = true;
        await cacheSet('overview', data);
    } catch (e) {
        if (e instanceof ApiError && e.status === 401) sync.sessionExpired = true;
        if (!(e instanceof ApiError)) sync.online = false;

        const cached = await cacheGet('overview');

        if (cached) {
            overview.value = cached.value;
            fromCache.value = true;
            cachedAt.value = cached.cached_at;
        } else {
            loadError.value = errorMessage(e);
        }
    } finally {
        campId.value ??= camps.value[0]?.id ?? null;
    }
}

/** Perbarui tampilan lokal segera setelah mencatat, supaya pengguna langsung melihat hasilnya walau belum terkirim. */
async function patchLocal(mutate) {
    if (!overview.value) return;

    mutate(overview.value);
    await cacheSet('overview', JSON.parse(JSON.stringify(overview.value)));
}

// ---- Riwayat lokal -----------------------------------------------------------------------------
const history = ref([]);
let subscription;

const STATUS = {
    pending: { text: 'Menunggu dikirim', class: 'text-quake-2' },
    sending: { text: 'Mengirim…', class: 'text-tsunami' },
    synced: { text: 'Terkirim', class: 'text-camp' },
    rejected: { text: 'Ditolak', class: 'text-quake-3' },
};

function statusFor(record) {
    return record.status === 'synced' && record.result === 'applied_stale' ? { text: 'Terkirim, ada laporan lebih baru', class: 'text-camp' } : STATUS[record.status];
}

// ---- Dialog ---------------------------------------------------------------------------------------
const dialog = ref(null); // occupancy | condition | demand | receive
const busy = ref(false);
const formError = ref(null);

const occupancy = reactive({ occupants: '', note: '', withLocation: true });
const condition = reactive({ severity: 'aman', note: '' });
const demand = reactive({ item_id: '', quantity: '', urgency: 'high', notes: '' });
const receipt = reactive({ dispatch: null, quantities: {}, notes: '', position: null, positionError: null, locating: false });

function open(name) {
    formError.value = null;
    busy.value = false;

    if (name === 'occupancy') Object.assign(occupancy, { occupants: camp.value?.current_occupants ?? '', note: '', withLocation: true });
    if (name === 'condition') Object.assign(condition, { severity: 'aman', note: '' });
    if (name === 'demand') Object.assign(demand, { item_id: '', quantity: '', urgency: 'high', notes: '' });

    dialog.value = name;
}

function close() {
    dialog.value = null;
}

async function maybeLocation(wanted) {
    if (!wanted) return {};

    const pos = await getPosition({ timeout: 5000 });

    return pos.error ? {} : { latitude: pos.latitude, longitude: pos.longitude, accuracy: pos.accuracy };
}

async function submitOccupancy() {
    busy.value = true;
    const n = Number(occupancy.occupants);
    const geo = await maybeLocation(occupancy.withLocation);

    await enqueue({
        type: 'occupancy_report',
        payload: { camp_id: camp.value.id, occupants: n, note: occupancy.note || null, ...geo },
        label: `Jumlah pengungsi ${camp.value.name}: ${formatQty(n)} orang`,
    });

    await patchLocal((o) => {
        const c = o.camps.find((x) => x.id === camp.value.id);
        c.current_occupants = n;
        c.occupants_updated_at = new Date().toISOString();
        c.status = c.status === 'active' && n >= c.capacity_people ? 'full' : c.status === 'full' && n < c.capacity_people ? 'active' : c.status;
    });

    done('Jumlah pengungsi dicatat.');
}

async function submitCondition() {
    if (condition.severity === 'darurat' && !condition.note.trim()) {
        formError.value = 'Jelaskan kondisi daruratnya singkat saja, supaya petugas tahu apa yang dibutuhkan.';

        return;
    }

    busy.value = true;

    await enqueue({
        type: 'condition_report',
        payload: { camp_id: camp.value.id, severity: condition.severity, note: condition.note || null, ...(await maybeLocation(true)) },
        label: `Kondisi ${camp.value.name}: ${condition.severity}`,
    });

    done('Laporan kondisi dicatat.');
}

async function submitDemand() {
    busy.value = true;
    const item = itemsById.value[demand.item_id];

    await enqueue({
        type: 'demand',
        payload: { camp_id: camp.value.id, item_id: demand.item_id, quantity_requested: String(demand.quantity), urgency_level: demand.urgency, notes: demand.notes || null },
        label: `Minta ${item.name} ${formatQty(demand.quantity)} ${item.unit} untuk ${camp.value.name}`,
    });

    done('Permintaan bantuan dicatat.');
}

function openReceive(dispatch) {
    Object.assign(receipt, {
        dispatch,
        quantities: Object.fromEntries(dispatch.items.map((i) => [i.id, String(i.quantity_dispatched)])),
        notes: '', position: null, positionError: null, locating: false,
    });
    formError.value = null;
    busy.value = false;
    dialog.value = 'receive';
    locate();
}

async function locate() {
    receipt.locating = true;
    receipt.positionError = null;

    const pos = await getPosition({ timeout: 12000 });

    if (pos.error) receipt.positionError = pos.error;
    else receipt.position = pos;

    receipt.locating = false;
}

async function submitReceive() {
    busy.value = true;
    const d = receipt.dispatch;

    await enqueue({
        type: 'dispatch_receipt',
        payload: {
            dispatch_id: d.id,
            latitude: receipt.position.latitude,
            longitude: receipt.position.longitude,
            accuracy: receipt.position.accuracy,
            received: d.items.map((i) => ({ dispatch_item_id: i.id, quantity: Number(receipt.quantities[i.id] || 0) })),
            notes: receipt.notes || null,
        },
        label: `Terima kiriman ${d.code}`,
    });

    // Kiriman ini sudah ditangani: sembunyikan dari daftar sampai server mengonfirmasi.
    await patchLocal((o) => (o.incoming = o.incoming.filter((x) => x.id !== d.id)));

    done('Penerimaan dicatat. Lokasi dan waktu tiba tersimpan bersamanya.');
}

function done(message) {
    busy.value = false;
    close();
    // Status koneksi di bagian atas yang menunjukkan terkirim atau belum; pesan ini hanya menegaskan bahwa data sudah aman.
    toast(`${message} Tersimpan di perangkat ini.`);
}

async function cancelItem(record) {
    await discard(record.id);
    toast('Dibatalkan dari antrean.', 'info');
}

// ---- Peringatan gempa langsung (saat online) ------------------------------------------------
const banner = ref(null);
const subscribed = [];

function listenThreats(list) {
    for (const c of list) {
        const name = `camps.${c.id}`;
        if (subscribed.includes(name)) continue;
        echo.private(name).listen('.camp.threatened', (e) => (banner.value = e));
        subscribed.push(name);
    }
}

// ---- Pasang ke layar utama -----------------------------------------------------------------
const installPrompt = ref(null);

async function install() {
    installPrompt.value.prompt();
    await installPrompt.value.userChoice;
    installPrompt.value = null;
}

let clock;

onMounted(async () => {
    clock = setInterval(() => (now.value = Date.now()), 30000);

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        installPrompt.value = e;
    });

    subscription = liveQuery(() => db.outbox.orderBy('created_at').reverse().limit(30).toArray()).subscribe({ next: (rows) => (history.value = rows) });

    await startSyncEngine();
    await loadOverview();
    listenThreats(camps.value);

});

// Setelah antrean selesai terkirim, ambil angka terbaru dari server (termasuk laporan orang lain).
watch(
    () => sync.lastSyncAt,
    () => sync.unsent === 0 && loadOverview(),
);

onBeforeUnmount(() => {
    clearInterval(clock);
    subscription?.unsubscribe();
    subscribed.forEach((n) => echo.leave(n));
});
</script>

<template>
    <Head title="Lapangan" />

    <div class="mx-auto min-h-dvh max-w-xl px-4 pb-24 pt-4">
        <header class="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-[#0a1630] via-[#10307f] to-[#1f56e8] p-5 text-white shadow-xl">
            <div class="flex items-center gap-3">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-white/15 text-xl font-extrabold" aria-hidden="true">{{ user.name.slice(0, 1).toUpperCase() }}</span>
                <div class="min-w-0 flex-1">
                    <h1 class="text-2xl font-extrabold leading-tight tracking-tight">Lapangan</h1>
                    <p class="truncate text-sm text-white/80">{{ user.name }}</p>
                </div>
            </div>
            <nav class="mt-4 flex flex-wrap gap-2 text-sm font-bold">
                <Link href="/akun" class="rounded-full bg-white px-4 py-2 text-brand shadow-sm">Akun</Link>
                <a href="/peta" class="rounded-full border border-white/40 px-4 py-2">Peta</a>
                <button type="button" class="rounded-full border border-white/40 px-4 py-2" @click="logout">Keluar</button>
            </nav>
        </header>

        <!-- Status koneksi dan antrean: selalu terlihat -->
        <section class="sticky top-0 z-10 -mx-4 mt-3 border-y border-line bg-mist/95 px-4 py-2.5 backdrop-blur" aria-label="Status koneksi dan pengiriman">
            <div class="flex items-center justify-between gap-3 text-sm">
                <p class="flex items-center gap-2" role="status">
                    <span class="size-2.5 rounded-full" :class="sync.online ? 'bg-camp' : 'bg-quake-3'" />
                    <span class="font-medium">{{ sync.online ? 'Online' : 'Offline' }}</span>
                    <span v-if="sync.unsent" class="text-quake-2">{{ sync.unsent }} laporan menunggu dikirim</span>
                    <span v-else class="text-muted">Semua laporan sudah terkirim</span>
                </p>
                <button
                    v-if="sync.unsent"
                    type="button"
                    class="shrink-0 rounded-md bg-ink px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60"
                    :disabled="sync.syncing"
                    @click="syncNow({ force: true })"
                >
                    {{ sync.syncing ? 'Mengirim…' : 'Kirim sekarang' }}
                </button>
            </div>
            <p v-if="!sync.online && sync.unsent" class="mt-1 text-xs text-muted">Aman: laporan tersimpan di perangkat ini dan dikirim otomatis begitu ada sinyal.</p>
        </section>

        <div v-if="sync.sessionExpired" class="mt-3 border-l-4 border-quake-2 bg-quake-2/5 px-3 py-2 text-sm" role="alert">
            Sesi Anda berakhir, jadi laporan belum bisa dikirim. Laporan tetap tersimpan di perangkat.
            <a href="/masuk" class="font-medium underline underline-offset-2">Masuk lagi</a> untuk mengirimkannya.
        </div>

        <!-- Peringatan gempa -->
        <div v-if="banner" class="mt-3 flex items-start gap-3 rounded-lg bg-ink p-3 text-white" role="alert">
            <span class="mag text-3xl" :style="{ color: magnitudeColor(banner.disaster.magnitude) }">{{ formatMagnitude(banner.disaster.magnitude) }}</span>
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold">Gempa mengancam {{ banner.camp.name }}</p>
                <p class="text-white/80">{{ banner.disaster.location }}, {{ formatKm(banner.distance_km) }} dari pusat gempa.</p>
                <p class="mt-1">Periksa kondisi pengungsi dan bangunan, lalu laporkan.</p>
            </div>
            <button type="button" class="px-1 text-xl leading-none text-white/70" aria-label="Tutup peringatan" @click="banner = null">&times;</button>
        </div>

        <p v-if="loadError" class="mt-4 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ loadError }}</p>

        <div v-else-if="overview && !camps.length" class="mt-6 text-sm text-muted">
            <p class="font-medium text-ink">Anda belum ditugaskan ke posko mana pun.</p>
            <p class="mt-1">Minta petugas BPBD menugaskan Anda ke sebuah posko, lalu muat ulang halaman ini.</p>
        </div>

        <template v-else-if="camp">
            <p v-if="fromCache" class="mt-3 text-xs text-muted">
                Data posko dari penyimpanan perangkat ({{ formatAgo(new Date(cachedAt).toISOString(), now) }}). Akan diperbarui saat online.
            </p>

            <!-- Posko -->
            <section class="mt-4">
                <label v-if="camps.length > 1" for="posko" class="block text-xs text-muted">Posko</label>
                <select v-if="camps.length > 1" id="posko" v-model="campId" class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2.5 text-base">
                    <option v-for="c in camps" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

                <div class="mt-3 flex items-end justify-between gap-4 border-b border-line pb-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold leading-tight">{{ camp.name }}</h2>
                        <p class="mt-0.5 text-xs text-muted">{{ isCoordinator ? 'Koordinator posko' : 'Relawan' }}, status {{ { active: 'aktif', full: 'penuh', evacuated: 'dikosongkan', closed: 'ditutup' }[camp.status] }}</p>
                    </div>
                    <p class="shrink-0 text-right">
                        <span class="mag text-4xl">{{ formatQty(camp.current_occupants) }}</span>
                        <span class="block text-xs text-muted">dari {{ formatQty(camp.capacity_people) }} orang</span>
                    </p>
                </div>
            </section>

            <!-- Aksi -->
            <section class="mt-4 grid grid-cols-2 gap-3" aria-label="Aksi lapangan">
                <button type="button" class="lift rounded-3xl bg-white p-4 text-left shadow-sm ring-1 ring-line" @click="open('occupancy')">
                    <span class="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-[#1f56e8] to-[#5b8dff] text-white shadow-md"><Icon name="user" :size="26" /></span>
                    <span class="mt-3 block text-lg font-extrabold leading-snug">Lapor jumlah pengungsi</span>
                    <span class="mt-1 block text-sm text-muted">Perbarui angka di posko</span>
                </button>
                <button type="button" class="lift rounded-3xl bg-white p-4 text-left shadow-sm ring-1 ring-line" @click="open('condition')">
                    <span class="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-[#ff7a2f] to-[#ffb23e] text-white shadow-md"><Icon name="warning" :size="26" /></span>
                    <span class="mt-3 block text-lg font-extrabold leading-snug">Lapor kondisi posko</span>
                    <span class="mt-1 block text-sm text-muted">Aman, waspada, atau darurat</span>
                </button>
                <button v-if="isCoordinator" type="button" class="lift rounded-3xl bg-white p-4 text-left shadow-sm ring-1 ring-line" @click="open('demand')">
                    <span class="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-[#7c4dff] to-[#b08cff] text-white shadow-md"><Icon name="bag" :size="26" /></span>
                    <span class="mt-3 block text-lg font-extrabold leading-snug">Minta bantuan</span>
                    <span class="mt-1 block text-sm text-muted">Makanan, obat, tenda, dan lainnya</span>
                </button>
                <div v-if="isCoordinator" class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-line">
                    <span class="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-[#0f9b8e] to-[#34d399] text-white shadow-md"><Icon name="box" :size="26" /></span>
                    <span class="mt-3 block text-lg font-extrabold leading-snug">Kiriman menuju posko</span>
                    <span class="mt-1 block text-sm text-muted">{{ incoming.length ? `${incoming.length} menunggu diterima` : 'Tidak ada kiriman' }}</span>
                </div>
            </section>

            <!-- Kiriman masuk -->
            <ul v-if="isCoordinator && incoming.length" class="mt-3 divide-y divide-line border-y border-line bg-surface">
                <li v-for="d in incoming" :key="d.id" class="flex items-center justify-between gap-3 px-3 py-3">
                    <div class="min-w-0 text-sm">
                        <p class="font-semibold">{{ d.code }}</p>
                        <p class="truncate text-muted">{{ d.warehouse.name }}</p>
                        <p class="truncate text-xs text-muted">{{ d.items.map((i) => `${i.item.name} ${formatQty(i.quantity_dispatched)} ${i.item.unit}`).join(', ') }}</p>
                    </div>
                    <button type="button" class="shrink-0 rounded-md bg-ink px-3 py-2 text-sm font-medium text-white" @click="openReceive(d)">Terima</button>
                </li>
            </ul>
        </template>

        <p v-else-if="!loadError" class="mt-6 text-sm text-muted">Memuat…</p>

        <!-- Riwayat di perangkat -->
        <section v-if="history.length" class="mt-8" aria-labelledby="riwayat">
            <h2 id="riwayat" class="text-sm font-semibold">Riwayat laporan di perangkat ini</h2>
            <ul class="mt-2 divide-y divide-line border-y border-line bg-surface">
                <li v-for="r in history" :key="r.id" class="px-3 py-2.5 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 flex-1">{{ r.label }}</p>
                        <button v-if="r.status === 'pending' || r.status === 'rejected' || r.status === 'synced'" type="button" class="shrink-0 text-xs text-muted underline underline-offset-2" @click="cancelItem(r)">
                            {{ r.status === 'pending' ? 'Batalkan' : 'Hapus' }}
                        </button>
                    </div>
                    <p class="mt-0.5 text-xs">
                        <span class="font-medium" :class="statusFor(r).class">{{ statusFor(r).text }}</span>
                        <span class="text-muted">, dicatat {{ formatWib(r.recorded_at) }}</span>
                    </p>
                    <p v-if="r.message" class="mt-0.5 text-xs" :class="r.status === 'rejected' ? 'text-quake-3' : 'text-muted'">{{ r.message }}</p>
                </li>
            </ul>
        </section>

        <button v-if="installPrompt" type="button" class="mt-6 w-full rounded-md border border-line px-4 py-2.5 text-sm" @click="install">Pasang di layar utama</button>

        <!-- Dialog: jumlah pengungsi -->
        <Dialog :open="dialog === 'occupancy'" title="Jumlah pengungsi" @close="close">
            <form id="f-occ" class="space-y-4 px-5 py-4" @submit.prevent="submitOccupancy">
                <div>
                    <label for="occ" class="block text-sm font-medium">Jumlah pengungsi sekarang di {{ camp?.name }}</label>
                    <input id="occ" v-model="occupancy.occupants" type="number" inputmode="numeric" min="0" max="100000" step="1" required class="mt-1 w-full rounded-md border border-line px-3 py-3 text-2xl font-semibold" />
                </div>
                <div>
                    <label for="occ-note" class="block text-sm font-medium">Catatan (opsional)</label>
                    <input id="occ-note" v-model="occupancy.note" type="text" maxlength="500" class="mt-1 w-full rounded-md border border-line px-3 py-2.5 text-base" />
                </div>
                <label class="flex items-start gap-2 text-sm"><input v-model="occupancy.withLocation" type="checkbox" class="mt-1" /> Sertakan lokasi saya sebagai bukti laporan</label>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2.5 text-sm" @click="close">Batal</button>
                <button type="submit" form="f-occ" class="rounded-md bg-ink px-5 py-2.5 text-sm font-medium text-white disabled:opacity-60" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan laporan' }}</button>
            </template>
        </Dialog>

        <!-- Dialog: kondisi -->
        <Dialog :open="dialog === 'condition'" title="Kondisi posko" @close="close">
            <form id="f-cond" class="space-y-4 px-5 py-4" @submit.prevent="submitCondition">
                <fieldset>
                    <legend class="text-sm font-medium">Bagaimana kondisi posko sekarang?</legend>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <label v-for="s in [{ v: 'aman', t: 'Aman', c: 'border-camp' }, { v: 'waspada', t: 'Waspada', c: 'border-quake-2' }, { v: 'darurat', t: 'Darurat', c: 'border-quake-4' }]" :key="s.v"
                            class="cursor-pointer rounded-lg border-2 px-2 py-3 text-center text-sm font-semibold"
                            :class="condition.severity === s.v ? `${s.c} bg-mist` : 'border-line'">
                            <input v-model="condition.severity" type="radio" name="sev" :value="s.v" class="sr-only" />
                            {{ s.t }}
                        </label>
                    </div>
                </fieldset>
                <div>
                    <label for="cond-note" class="block text-sm font-medium">Keterangan<template v-if="condition.severity === 'darurat'"> (wajib)</template></label>
                    <textarea id="cond-note" v-model="condition.note" rows="3" maxlength="1000" class="mt-1 w-full rounded-md border border-line px-3 py-2.5 text-base" placeholder="Apa yang terjadi dan apa yang dibutuhkan?" />
                </div>
                <p v-if="formError" class="border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ formError }}</p>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2.5 text-sm" @click="close">Batal</button>
                <button type="submit" form="f-cond" class="rounded-md bg-ink px-5 py-2.5 text-sm font-medium text-white disabled:opacity-60" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan laporan' }}</button>
            </template>
        </Dialog>

        <!-- Dialog: minta bantuan -->
        <Dialog :open="dialog === 'demand'" title="Minta bantuan" @close="close">
            <form id="f-dem" class="space-y-4 px-5 py-4" @submit.prevent="submitDemand">
                <div>
                    <label for="dem-item" class="block text-sm font-medium">Barang yang dibutuhkan</label>
                    <select id="dem-item" v-model="demand.item_id" required class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2.5 text-base">
                        <option value="" disabled>Pilih barang</option>
                        <option v-for="i in overview?.items ?? []" :key="i.id" :value="i.id">{{ i.name }} ({{ i.unit }})</option>
                    </select>
                </div>
                <div>
                    <label for="dem-qty" class="block text-sm font-medium">Jumlah<template v-if="itemsById[demand.item_id]"> ({{ itemsById[demand.item_id].unit }})</template></label>
                    <input id="dem-qty" v-model="demand.quantity" type="number" inputmode="decimal" min="0.01" step="0.01" required class="mt-1 w-full rounded-md border border-line px-3 py-3 text-2xl font-semibold" />
                </div>
                <fieldset>
                    <legend class="text-sm font-medium">Seberapa mendesak?</legend>
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        <label v-for="(u, key) in { low: URGENCY.low, medium: URGENCY.medium, high: URGENCY.high, critical: URGENCY.critical }" :key="key"
                            class="cursor-pointer rounded-lg border-2 px-1 py-2.5 text-center text-xs font-semibold"
                            :style="demand.urgency === key ? { borderColor: u.color } : {}" :class="demand.urgency === key ? 'bg-mist' : 'border-line'">
                            <input v-model="demand.urgency" type="radio" name="urg" :value="key" class="sr-only" />
                            {{ u.label }}
                        </label>
                    </div>
                </fieldset>
                <div>
                    <label for="dem-notes" class="block text-sm font-medium">Catatan (opsional)</label>
                    <input id="dem-notes" v-model="demand.notes" type="text" maxlength="500" class="mt-1 w-full rounded-md border border-line px-3 py-2.5 text-base" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2.5 text-sm" @click="close">Batal</button>
                <button type="submit" form="f-dem" class="rounded-md bg-ink px-5 py-2.5 text-sm font-medium text-white disabled:opacity-60" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Kirim permintaan' }}</button>
            </template>
        </Dialog>

        <!-- Dialog: terima kiriman -->
        <Dialog :open="dialog === 'receive'" title="Terima kiriman" @close="close">
            <form v-if="receipt.dispatch" id="f-rec" class="space-y-4 px-5 py-4" @submit.prevent="submitReceive">
                <p class="text-sm text-muted">{{ receipt.dispatch.code }} dari {{ receipt.dispatch.warehouse.name }}. Lokasi Anda dicatat sebagai bukti barang benar-benar tiba di posko.</p>

                <div class="rounded-md border border-line px-3 py-2.5 text-sm" role="status">
                    <p v-if="receipt.locating" class="text-muted">Membaca lokasi…</p>
                    <p v-else-if="receipt.position" class="text-camp">Lokasi terbaca, akurasi sekitar {{ receipt.position.accuracy }} m.</p>
                    <p v-else class="text-quake-3">{{ receipt.positionError }}</p>
                    <button v-if="!receipt.locating && !receipt.position" type="button" class="mt-1 text-xs font-medium underline underline-offset-2" @click="locate">Coba baca lokasi lagi</button>
                </div>

                <fieldset>
                    <legend class="text-sm font-medium">Jumlah diterima</legend>
                    <ul class="mt-2 space-y-2">
                        <li v-for="i in receipt.dispatch.items" :key="i.id" class="flex items-center justify-between gap-3 text-sm">
                            <label :for="`rq-${i.id}`" class="min-w-0 flex-1">{{ i.item.name }}<span class="block text-xs text-muted">dikirim {{ formatQty(i.quantity_dispatched) }} {{ i.item.unit }}</span></label>
                            <input :id="`rq-${i.id}`" v-model="receipt.quantities[i.id]" type="number" inputmode="decimal" step="0.01" min="0" :max="i.quantity_dispatched" required class="w-28 rounded-md border border-line px-2 py-2.5 text-right text-lg font-semibold" />
                        </li>
                    </ul>
                </fieldset>

                <div>
                    <label for="rec-notes" class="block text-sm font-medium">Catatan (opsional)</label>
                    <input id="rec-notes" v-model="receipt.notes" type="text" maxlength="1000" class="mt-1 w-full rounded-md border border-line px-3 py-2.5 text-base" />
                </div>
                <p v-if="!receipt.position" class="text-xs text-muted">Penerimaan baru bisa dicatat setelah lokasi terbaca.</p>
            </form>
            <template #footer>
                <button type="button" class="rounded-md border border-line px-4 py-2.5 text-sm" @click="close">Batal</button>
                <button type="submit" form="f-rec" class="rounded-md bg-ink px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50" :disabled="busy || !receipt.position">{{ busy ? 'Menyimpan…' : 'Catat penerimaan' }}</button>
            </template>
        </Dialog>

        <ToastHost />
    </div>
</template>
