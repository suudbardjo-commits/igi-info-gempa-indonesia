<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../../Layouts/LogistikLayout.vue';
import { api, errorMessage } from '../../lib/api';
import { formatAgo, formatWib } from '../../lib/quake';

const data = ref({ pending: [], recent: [], roles: [], camps: [], warehouses: [] });
const loading = ref(true);
const error = ref(null);
const busy = ref(null);
// Pilihan per pendaftar: { role, camp_id, warehouse_id, reject: bool, reason }
const choice = reactive({});

// Petugas aktif dan pengaturan ulang kata sandi
const staff = ref([]);
const query = ref('');
const confirmId = ref(null);
const resetting = ref(null);
const result = ref(null); // { name, email, temporary_password } hanya di memori, tampil sekali
const copied = ref(false);

const shownStaff = computed(() => {
    const q = query.value.trim().toLowerCase();

    return staff.value.filter((u) => !q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q) || (u.organization ?? '').toLowerCase().includes(q));
});

async function resetPassword(u) {
    resetting.value = u.id;
    error.value = null;
    copied.value = false;

    try {
        result.value = await api(`/staff/${u.id}/reset-password`, { method: 'POST' }).then((r) => ({ ...r.user, temporary_password: r.temporary_password }));
        confirmId.value = null;
        await loadStaff();
    } catch (e) {
        error.value = `${u.name}: ${errorMessage(e)}`;
    } finally {
        resetting.value = null;
    }
}

async function copyTemporary() {
    try {
        await navigator.clipboard.writeText(result.value.temporary_password);
        copied.value = true;
    } catch {
        // Di alamat http biasa papan klip tidak tersedia; kata sandinya sudah bisa dipilih dan disalin manual.
        copied.value = false;
        error.value = 'Papan klip tidak tersedia di alamat ini. Pilih kata sandinya lalu salin dengan menahan teks.';
    }
}

const ROLE_LABEL = { 'field-volunteer': 'Relawan lapangan', 'camp-coordinator': 'Koordinator posko', 'warehouse-manager': 'Pengelola gudang', 'bpbd-operator': 'Operator BPBD' };

const needsCamp = (role) => role === 'field-volunteer' || role === 'camp-coordinator';

async function load() {
    loading.value = true;
    error.value = null;

    try {
        data.value = await api('/registrations');
        // Lencana jumlah di menu ikut diperbarui setelah setiap tinjauan.
        router.reload({ only: ['pendingRegistrations'] });
        await loadStaff();

        for (const u of data.value.pending) {
            choice[u.id] ??= { role: data.value.roles.some((r) => r.value === u.requested_role) ? u.requested_role : data.value.roles[0]?.value, camp_id: '', warehouse_id: '', reject: false, reason: '' };
        }
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function loadStaff() {
    try {
        staff.value = (await api('/staff')).data;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function approve(u) {
    const c = choice[u.id];
    busy.value = u.id;
    error.value = null;

    try {
        await api(`/registrations/${u.id}/approve`, { method: 'POST', body: { role: c.role, camp_id: needsCamp(c.role) ? c.camp_id || null : null, warehouse_id: c.role === 'warehouse-manager' ? c.warehouse_id || null : null } });
        await load();
    } catch (e) {
        error.value = `${u.name}: ${errorMessage(e)}`;
    } finally {
        busy.value = null;
    }
}

async function reject(u) {
    busy.value = u.id;
    error.value = null;

    try {
        await api(`/registrations/${u.id}/reject`, { method: 'POST', body: { reason: choice[u.id].reason } });
        await load();
    } catch (e) {
        error.value = `${u.name}: ${errorMessage(e)}`;
    } finally {
        busy.value = null;
    }
}

const pendingCount = computed(() => data.value.pending.length);
const input = 'mt-1 w-full rounded-md border border-line bg-surface px-3 py-2 text-sm';

onMounted(load);
</script>

<template>
    <Head title="Pendaftaran petugas" />

    <Layout title="Pendaftaran petugas">
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Akun baru tidak punya akses apa pun sampai Anda menyetujuinya. Peran yang diminta hanyalah usulan; Anda menetapkan peran dan tempat tugasnya. Pastikan orangnya
            dikenal sebelum menyetujui.
        </p>

        <p v-if="error" class="mt-4 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ error }}</p>

        <h2 class="mt-6 font-display text-2xl leading-none">Menunggu ditinjau <span class="text-muted">({{ pendingCount }})</span></h2>

        <ul v-if="pendingCount" class="mt-3 space-y-4">
            <li v-for="u in data.pending" :key="u.id" class="border border-line bg-surface p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ u.name }}</p>
                        <p class="text-sm text-muted">{{ u.email }}<template v-if="u.phone_number">, {{ u.phone_number }}</template></p>
                    </div>
                    <p class="text-xs text-muted" :title="formatWib(u.registered_at)">Mendaftar {{ formatAgo(u.registered_at) }}</p>
                </div>
                <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-muted">Meminta peran</dt><dd>{{ ROLE_LABEL[u.requested_role] ?? u.requested_role }}</dd></div>
                    <div><dt class="text-xs text-muted">Instansi atau posko</dt><dd>{{ u.organization }}</dd></div>
                    <div v-if="u.request_note" class="sm:col-span-2"><dt class="text-xs text-muted">Keterangan</dt><dd class="whitespace-pre-line">{{ u.request_note }}</dd></div>
                </dl>

                <div v-if="choice[u.id]" class="mt-4 border-t border-line pt-3">
                    <template v-if="!choice[u.id].reject">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-sm">
                                <span class="font-medium">Tetapkan peran</span>
                                <select v-model="choice[u.id].role" :class="input">
                                    <option v-for="r in data.roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                                </select>
                            </label>

                            <label v-if="needsCamp(choice[u.id].role)" class="block text-sm">
                                <span class="font-medium">Posko tugas</span>
                                <select v-model="choice[u.id].camp_id" :class="input">
                                    <option value="">Pilih posko…</option>
                                    <option v-for="c in data.camps" :key="c.id" :value="c.id" :disabled="choice[u.id].role === 'camp-coordinator' && c.has_coordinator">
                                        {{ c.name }} ({{ c.code }}){{ choice[u.id].role === 'camp-coordinator' && c.has_coordinator ? ', sudah punya koordinator' : '' }}
                                    </option>
                                </select>
                            </label>

                            <label v-if="choice[u.id].role === 'warehouse-manager'" class="block text-sm">
                                <span class="font-medium">Gudang yang dikelola</span>
                                <select v-model="choice[u.id].warehouse_id" :class="input">
                                    <option value="">Pilih gudang…</option>
                                    <option v-for="w in data.warehouses" :key="w.id" :value="w.id" :disabled="w.has_manager">{{ w.name }} ({{ w.code }}){{ w.has_manager ? ', sudah punya pengelola' : '' }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" class="rounded-md bg-trench px-4 py-2 text-sm font-bold text-white hover:bg-deep disabled:opacity-50" :disabled="busy === u.id" @click="approve(u)">
                                {{ busy === u.id ? 'Menyimpan…' : 'Setujui' }}
                            </button>
                            <button type="button" class="rounded-md border border-line px-4 py-2 text-sm font-semibold hover:bg-mist" @click="choice[u.id].reject = true">Tolak</button>
                        </div>
                    </template>

                    <template v-else>
                        <label class="block text-sm">
                            <span class="font-medium">Alasan penolakan</span>
                            <input v-model="choice[u.id].reason" maxlength="200" :class="input" placeholder="Contoh: tidak dikenal oleh BPBD setempat" />
                        </label>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" class="rounded-md bg-quake-4 px-4 py-2 text-sm font-bold text-white disabled:opacity-50" :disabled="busy === u.id || choice[u.id].reason.trim().length < 3" @click="reject(u)">Tolak pendaftaran</button>
                            <button type="button" class="rounded-md border border-line px-4 py-2 text-sm font-semibold hover:bg-mist" @click="choice[u.id].reject = false">Batal</button>
                        </div>
                    </template>
                </div>
            </li>
        </ul>
        <p v-else-if="!loading" class="mt-3 border-t border-line py-8 text-sm text-muted">Tidak ada pendaftaran yang menunggu. Pendaftar baru muncul di sini setelah mengisi formulir di halaman Daftar.</p>

        <h2 class="mt-10 font-display text-2xl leading-none">Petugas aktif</h2>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Bila seorang petugas lupa kata sandi, atur ulang di sini. Anda mendapat kata sandi sementara yang tampil <strong class="text-ink">sekali</strong>; sampaikan lewat jalur
            yang aman (telepon atau bertemu langsung). Petugas wajib menggantinya saat masuk, dan semua perangkat lamanya keluar.
        </p>

        <section v-if="result" class="mt-4 max-w-xl border-l-4 border-camp bg-camp/5 px-4 py-3" role="status" aria-label="Kata sandi sementara">
            <p class="text-sm font-semibold">Kata sandi sementara untuk {{ result.name }} ({{ result.email }})</p>
            <p class="mt-2 select-all font-mono text-2xl font-semibold tracking-wider" aria-label="Kata sandi sementara">{{ result.temporary_password }}</p>
            <p class="mt-2 text-xs text-muted">Ini tidak akan ditampilkan lagi dan tidak tersimpan di mana pun. Bila hilang, atur ulang sekali lagi.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line bg-surface px-3 py-1.5 text-sm font-semibold hover:bg-mist" @click="copyTemporary">{{ copied ? 'Tersalin' : 'Salin' }}</button>
                <button type="button" class="rounded-md bg-trench px-3 py-1.5 text-sm font-bold text-white hover:bg-deep" @click="result = null">Sudah saya sampaikan</button>
            </div>
        </section>

        <label class="mt-4 block max-w-sm text-sm">
            <span class="font-medium">Cari petugas</span>
            <input v-model="query" type="search" placeholder="Nama, email, atau instansi" class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2 text-sm" />
        </label>

        <ul v-if="shownStaff.length" class="mt-3 border-t border-line bg-surface">
            <li v-for="u in shownStaff" :key="u.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 text-sm">
                <div class="min-w-0">
                    <p class="font-semibold">{{ u.name }}<span v-if="u.must_change_password" class="ml-2 rounded bg-quake-2 px-1.5 py-0.5 text-xs font-bold text-ink">Belum mengganti kata sandi sementara</span></p>
                    <p class="text-muted">{{ u.email }}<template v-if="u.organization">, {{ u.organization }}</template></p>
                    <p class="text-xs text-muted">
                        {{ u.roles.map((r) => ROLE_LABEL[r] ?? r).join(', ') || 'Tanpa peran' }}<template v-if="u.password_reset_at">, terakhir diatur ulang {{ formatAgo(u.password_reset_at) }}</template>
                    </p>
                </div>

                <template v-if="u.can_reset">
                    <div v-if="confirmId === u.id" class="flex flex-wrap items-center gap-2">
                        <span class="text-xs">Atur ulang kata sandi {{ u.name }}?</span>
                        <button type="button" class="rounded-md bg-quake-4 px-3 py-1.5 text-sm font-bold text-white disabled:opacity-50" :disabled="resetting === u.id" @click="resetPassword(u)">{{ resetting === u.id ? 'Mengatur…' : 'Ya, atur ulang' }}</button>
                        <button type="button" class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold hover:bg-mist" @click="confirmId = null">Batal</button>
                    </div>
                    <button v-else type="button" class="shrink-0 rounded-md border border-line px-3 py-1.5 text-sm font-semibold hover:bg-mist" @click="confirmId = u.id">Atur ulang kata sandi</button>
                </template>
            </li>
        </ul>
        <p v-else-if="!loading" class="mt-3 border-t border-line py-6 text-sm text-muted">{{ query ? 'Tidak ada petugas yang cocok dengan pencarian.' : 'Belum ada petugas aktif.' }}</p>

        <h2 class="mt-10 font-display text-2xl leading-none">Baru saja ditinjau</h2>
        <ul v-if="data.recent.length" class="mt-3 border-t border-line bg-surface">
            <li v-for="u in data.recent" :key="u.id" class="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-4 py-3 text-sm">
                <span>
                    <span class="font-semibold">{{ u.name }}</span>
                    <span class="text-muted">, {{ u.email }}</span>
                </span>
                <span :class="u.status === 'approved' ? 'text-camp' : 'text-quake-4'" class="font-medium">
                    {{ u.status === 'approved' ? 'Disetujui' : `Ditolak: ${u.reject_reason}` }}
                    <span class="font-normal text-muted">{{ formatAgo(u.reviewed_at) }}</span>
                </span>
            </li>
        </ul>
        <p v-else-if="!loading" class="mt-2 text-sm text-muted">Belum ada.</p>
    </Layout>
</template>
