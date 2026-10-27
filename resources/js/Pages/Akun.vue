<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import HeroWaves from '../components/HeroWaves.vue';
import ToastHost from '../components/ToastHost.vue';
import { useToast } from '../composables/useToast';
import { echo } from '../echo';
import { api, errorMessage } from '../lib/api';
import { formatKm, formatQty } from '../lib/logistik';
import { formatAgo, formatMagnitude, formatWib, hasTsunamiPotential, magnitudeColor, magnitudeInk } from '../lib/quake';
import { logout } from '../lib/session';

const page = usePage();
const { toast } = useToast();

const user = computed(() => page.props.auth.user);
const ROLE_LABEL = { 'super-admin': 'Super admin', 'bpbd-operator': 'Operator BPBD', 'warehouse-manager': 'Pengelola gudang', 'camp-coordinator': 'Koordinator posko', 'field-volunteer': 'Relawan lapangan' };
// Kata sandi sementara dari operator: halaman ini hanya untuk menggantinya; peringatan dan Telegram dimuat setelahnya.
const mustChange = computed(() => !!user.value.must_change_password);
const inConsole = computed(() => user.value.can['demand.view-all'] || user.value.can['dispatch.create']);

const camps = ref([]);
const alerts = ref([]);
const loading = ref(true);
const error = ref(null);
const connection = ref('connecting');
const banner = ref(null);
const now = ref(Date.now());

const telegram = ref(null);
const code = ref(null);
const busy = ref(false);

const pw = ref({ current_password: '', password: '', password_confirmation: '' });
const pwBusy = ref(false);
const pwError = ref(null);
const pwOk = ref(false);

async function changePassword() {
    pwBusy.value = true;
    pwError.value = null;
    pwOk.value = false;

    try {
        await api('/me/password', { method: 'POST', body: { ...pw.value } });
        pw.value = { current_password: '', password: '', password_confirmation: '' };
        pwOk.value = true;
        toast('Kata sandi sudah diganti.', 'success');

        // Status "wajib ganti" ikut diperbarui; bila tadinya wajib, peringatan dan Telegram dimuat sekarang.
        const wasForced = mustChange.value;
        router.reload({ only: ['auth'], onSuccess: () => wasForced && startNormal() });
    } catch (e) {
        pwError.value = errorMessage(e);
    } finally {
        pwBusy.value = false;
    }
}

const connectionText = computed(
    () => ({ connected: 'Peringatan langsung aktif', connecting: 'Menyambungkan…', offline: 'Peringatan langsung terputus' })[connection.value],
);

async function loadOverview() {
    try {
        const res = await api('/me/overview');
        camps.value = res.camps;
        alerts.value = res.alerts;
        subscribe(res.camps);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

// ---- Peringatan langsung ---------------------------------------------------------------------
let subscribed = [];

function subscribe(list) {
    for (const camp of list) {
        const name = `camps.${camp.id}`;
        echo.private(name).listen('.camp.threatened', onThreat);
        subscribed.push(name);
    }
}

function onThreat(e) {
    if (alerts.value.some((a) => a.disaster.id === e.disaster.id && a.camp.id === e.camp.id)) return;

    const alert = { disaster: e.disaster, camp: e.camp, distance_km: e.distance_km, live: true };
    alerts.value = [alert, ...alerts.value];
    banner.value = alert;
}

function onState({ current }) {
    connection.value = current === 'connected' ? 'connected' : current === 'connecting' ? 'connecting' : 'offline';
}

// ---- Telegram --------------------------------------------------------------------------------
let poll;

async function loadTelegram() {
    try {
        telegram.value = await api('/telegram');
    } catch (e) {
        toast(errorMessage(e), 'error');
    }
}

async function requestCode() {
    busy.value = true;

    try {
        code.value = await api('/telegram/link-code', { method: 'POST' });
        // Pantau sampai bot menerima kodenya, lalu tampilkan status terhubung.
        clearInterval(poll);
        poll = setInterval(async () => {
            await loadTelegram();

            if (telegram.value?.linked) {
                clearInterval(poll);
                code.value = null;
                toast('Telegram berhasil terhubung ke akun Anda.');
            }
        }, 4000);
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        busy.value = false;
    }
}

async function unlink() {
    busy.value = true;

    try {
        await api('/telegram', { method: 'DELETE' });
        toast('Telegram diputus. Anda tidak akan menerima peringatan lewat Telegram.');
        code.value = null;
        await loadTelegram();
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        busy.value = false;
    }
}

let clock;
let connectionBound;

let started = false;

/** Memuat peringatan dan Telegram serta menyambung siaran. Ditunda sampai kata sandi sementara diganti. */
function startNormal() {
    if (started) return;
    started = true;

    loadOverview();
    loadTelegram();

    connectionBound = echo.connector.pusher.connection;
    onState({ current: connectionBound.state });
    connectionBound.bind('state_change', onState);
}

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30000);

    if (mustChange.value) loading.value = false;
    else startNormal();
});

onBeforeUnmount(() => {
    clearInterval(clock);
    clearInterval(poll);
    subscribed.forEach((name) => echo.leave(name));
    connectionBound?.unbind('state_change', onState);
});
</script>

<template>
    <Head title="Akun" />

    <div class="mx-auto min-h-dvh max-w-3xl px-4 pb-16 pt-5 md:px-6">
        <header class="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-[#0a1630] via-[#10307f] to-[#1f56e8] p-6 text-white shadow-xl md:p-8">
            <HeroWaves :waves="false" />
            <div class="relative flex flex-wrap items-center gap-4">
                <span class="grid size-16 place-items-center rounded-3xl bg-white/15 text-3xl font-extrabold backdrop-blur-sm" aria-hidden="true">{{ user.name.slice(0, 1).toUpperCase() }}</span>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-3xl font-extrabold tracking-tight">{{ user.name }}</h1>
                    <p class="mt-1 flex flex-wrap gap-2 text-sm font-bold">
                        <span v-for="r in user.roles" :key="r" class="rounded-full bg-white/20 px-3 py-1">{{ ROLE_LABEL[r] ?? r }}</span>
                    </p>
                </div>
            </div>
            <nav class="relative mt-5 flex flex-wrap gap-2 text-sm font-bold" aria-label="Pintasan">
                <Link v-if="inConsole" href="/logistik" class="rounded-full bg-white px-4 py-2.5 text-brand shadow-sm transition-transform hover:scale-[1.03]">Konsol logistik</Link>
                <Link v-if="user.can['field-report.create']" href="/lapangan" class="rounded-full bg-white px-4 py-2.5 text-brand shadow-sm transition-transform hover:scale-[1.03]">Aplikasi lapangan</Link>
                <a href="/peta" class="rounded-full border border-white/40 px-4 py-2.5 transition-colors hover:bg-white/15">Peta publik</a>
                <button type="button" class="rounded-full border border-white/40 px-4 py-2.5 transition-colors hover:bg-white/15" @click="logout">Keluar</button>
            </nav>
        </header>

        <div v-if="mustChange" class="mt-5 border-l-4 border-quake-2 bg-quake-2/10 px-4 py-3" role="alert">
            <p class="text-sm font-semibold">Kata sandi Anda baru diatur ulang oleh operator.</p>
            <p class="mt-1 text-sm">Ganti dengan kata sandi buatan Anda sendiri di bawah. Sampai itu selesai, halaman lain belum bisa dipakai.</p>
        </div>

        <!-- Peringatan yang baru masuk lewat WebSocket -->
        <div v-if="banner" class="mt-5 flex items-start gap-4 rounded-lg bg-ink p-4 text-white" role="alert">
            <span class="mag text-4xl" :style="{ color: magnitudeColor(banner.disaster.magnitude) }">{{ formatMagnitude(banner.disaster.magnitude) }}</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">Gempa baru mengancam {{ banner.camp.name }}</p>
                <p class="mt-0.5 text-sm text-white/80">{{ banner.disaster.location }}, {{ formatKm(banner.distance_km) }} dari pusat gempa.</p>
                <p class="mt-2 text-sm">Segera periksa kondisi pengungsi dan bangunan posko, lalu laporkan ke petugas koordinasi.</p>
            </div>
            <button type="button" class="px-1 text-xl leading-none text-white/70 hover:text-white" aria-label="Tutup peringatan" @click="banner = null">&times;</button>
        </div>

        <!-- Peringatan untuk posko -->
        <section v-if="!mustChange" class="mt-8" aria-labelledby="peringatan">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="peringatan" class="text-base font-semibold">Peringatan untuk posko Anda</h2>
                <p v-if="camps.length" class="flex items-center gap-1.5 text-xs text-muted" role="status">
                    <span class="size-2 rounded-full" :class="{ 'bg-camp': connection === 'connected', 'bg-quake-1': connection === 'connecting', 'bg-quake-3': connection === 'offline' }" />
                    {{ connectionText }}
                </p>
            </div>

            <p v-if="loading" class="mt-3 text-sm text-muted">Memuat…</p>
            <p v-else-if="error" class="mt-3 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ error }}</p>

            <div v-else-if="!camps.length" class="mt-3 border-t border-line py-6 text-sm text-muted">
                <p class="font-medium text-ink">Akun Anda belum ditetapkan sebagai koordinator posko mana pun.</p>
                <p class="mt-1">Hubungi petugas BPBD untuk ditetapkan. Setelah itu peringatan gempa untuk posko Anda muncul di sini.</p>
            </div>

            <template v-else>
                <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted">
                    <li v-for="c in camps" :key="c.id">
                        <span class="font-medium text-ink">{{ c.name }}</span>, {{ formatQty(c.current_occupants) }} dari {{ formatQty(c.capacity_people) }} orang
                    </li>
                </ul>

                <ul v-if="alerts.length" class="mt-4 border-t border-line bg-surface">
                    <li v-for="a in alerts" :key="a.disaster.id + a.camp.id" class="grid grid-cols-[3.6rem_1fr_auto] items-start gap-x-3 border-b border-line px-4 py-3">
                        <span class="mag pt-0.5 text-3xl" :style="{ color: magnitudeInk(a.disaster.magnitude) }">{{ formatMagnitude(a.disaster.magnitude) }}</span>
                        <span class="min-w-0 text-sm">
                            <span class="block font-medium">{{ a.disaster.location }}</span>
                            <span class="block text-muted">{{ a.camp.name }} berjarak {{ formatKm(a.distance_km) }} dari pusat gempa (radius dampak {{ a.disaster.impact_radius_km }} km)</span>
                            <span v-if="hasTsunamiPotential(a.disaster.tsunami_potential)" class="mt-1 inline-block rounded bg-tsunami px-1.5 py-0.5 text-xs font-medium text-white">Berpotensi tsunami</span>
                        </span>
                        <span class="pt-1 text-xs text-muted" :title="formatWib(a.disaster.occurred_at)">{{ formatAgo(a.disaster.occurred_at, now) }}</span>
                    </li>
                </ul>
                <div v-else class="mt-4 border-t border-line py-6 text-sm text-muted">
                    <p class="font-medium text-ink">Tidak ada gempa yang mengancam posko Anda dalam 72 jam terakhir.</p>
                    <p class="mt-1">Halaman ini menampilkan peringatan baru begitu masuk, tanpa perlu dimuat ulang.</p>
                </div>
            </template>
        </section>

        <!-- Telegram -->
        <section v-if="!mustChange" class="mt-10 border-t border-line pt-6" aria-labelledby="telegram">
            <h2 id="telegram" class="text-base font-semibold">Notifikasi Telegram</h2>
            <p class="mt-1 max-w-xl text-sm text-muted">
                Peringatan gempa juga dikirim ke Telegram supaya sampai walau aplikasi tidak sedang dibuka. Koordinator posko menerimanya untuk postonya;
                operator BPBD untuk gempa besar atau berpotensi tsunami.
            </p>

            <p v-if="!telegram" class="mt-3 text-sm text-muted">Memuat status…</p>

            <p v-else-if="!telegram.bot_configured" class="mt-3 border-l-4 border-quake-2 bg-quake-2/5 px-3 py-2 text-sm">
                Bot Telegram belum disiapkan oleh administrator, jadi notifikasi Telegram belum tersedia. Peringatan di halaman ini tetap berjalan.
            </p>

            <template v-else-if="telegram.linked">
                <p class="mt-3 text-sm"><span class="font-medium text-camp">Terhubung</span> sejak {{ formatWib(telegram.linked_at) }}.</p>
                <button type="button" class="mt-3 rounded-md border border-line px-4 py-2 text-sm hover:bg-mist disabled:opacity-60" :disabled="busy" @click="unlink">Putuskan Telegram</button>
            </template>

            <template v-else>
                <div v-if="code" class="mt-4 border-l-4 border-tsunami bg-tsunami/5 px-4 py-3">
                    <p class="text-sm">Buka Telegram, cari <strong>@{{ code.bot_username || 'bot peringatan' }}</strong>, lalu kirim pesan ini dalam 15 menit:</p>
                    <p class="mt-2 select-all font-mono text-xl font-semibold tracking-widest" aria-label="Pesan untuk dikirim ke bot">/start {{ code.code }}</p>
                    <p class="mt-2 text-xs text-muted" role="status">Menunggu bot menerima kode Anda. Halaman ini akan berubah sendiri begitu terhubung.</p>
                </div>
                <button type="button" class="mt-3 rounded-md bg-ink px-4 py-2 text-sm font-medium text-white disabled:opacity-60" :disabled="busy" @click="requestCode">
                    {{ code ? 'Buat kode baru' : 'Hubungkan Telegram' }}
                </button>
            </template>
        </section>

        <!-- Kata sandi -->
        <section class="mt-10 border-t border-line pt-6" :class="mustChange ? 'mt-8 border-t-0 pt-0' : ''" aria-labelledby="sandi">
            <h2 id="sandi" class="text-base font-semibold">Ganti kata sandi</h2>
            <p class="mt-1 max-w-xl text-sm text-muted">
                Minimal 10 karakter dan memuat huruf dan angka. Setelah diganti, perangkat lain yang memakai akun ini harus masuk lagi.
            </p>

            <form class="mt-4 max-w-md space-y-4" @submit.prevent="changePassword">
                <div>
                    <label for="sandi-lama" class="block text-sm font-medium">{{ mustChange ? 'Kata sandi sementara dari operator' : 'Kata sandi saat ini' }}</label>
                    <input id="sandi-lama" v-model="pw.current_password" type="password" autocomplete="current-password" required class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2 text-sm" />
                </div>
                <div>
                    <label for="sandi-baru" class="block text-sm font-medium">Kata sandi baru</label>
                    <input id="sandi-baru" v-model="pw.password" type="password" autocomplete="new-password" required minlength="10" class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2 text-sm" />
                </div>
                <div>
                    <label for="sandi-ulang" class="block text-sm font-medium">Ulangi kata sandi baru</label>
                    <input id="sandi-ulang" v-model="pw.password_confirmation" type="password" autocomplete="new-password" required class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2 text-sm" />
                </div>

                <p v-if="pwError" class="border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ pwError }}</p>
                <p v-if="pwOk" class="border-l-4 border-camp bg-camp/5 px-3 py-2 text-sm" role="status">Kata sandi sudah diganti.</p>

                <button type="submit" class="rounded-md bg-trench px-4 py-2.5 text-sm font-bold text-white hover:bg-deep disabled:opacity-60" :disabled="pwBusy">
                    {{ pwBusy ? 'Menyimpan…' : 'Simpan kata sandi baru' }}
                </button>
            </form>
        </section>

        <ToastHost />
    </div>
</template>
