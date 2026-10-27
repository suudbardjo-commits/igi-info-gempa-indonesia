<script setup>
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import Layout from '../../Layouts/LogistikLayout.vue';
import { api, errorMessage } from '../../lib/api';
import { formatQty } from '../../lib/logistik';
import { formatWib } from '../../lib/quake';

const logs = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const actions = ref({});
const action = ref('');
const loading = ref(true);
const error = ref(null);

const integrity = ref(null);
const checking = ref(false);
const integrityError = ref(null);

async function load(page = 1) {
    loading.value = true;
    error.value = null;

    try {
        const res = await api('/audit-logs', { query: { action: action.value, page } });
        logs.value = res.data;
        meta.value = res.meta;
        actions.value = res.actions;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function check() {
    checking.value = true;
    integrityError.value = null;

    try {
        integrity.value = await api('/audit-logs/integrity');
    } catch (e) {
        integrityError.value = errorMessage(e);
    } finally {
        checking.value = false;
    }
}

function place(l) {
    if (!l) return '–';

    const base = `${l.lat.toLocaleString('id-ID', { maximumFractionDigits: 5 })}, ${l.lng.toLocaleString('id-ID', { maximumFractionDigits: 5 })}`;

    return l.accuracy_m ? `${base} (±${Math.round(l.accuracy_m)} m)` : base;
}

onMounted(() => {
    load();
    check();
});
</script>

<template>
    <Head title="Jejak audit" />

    <Layout title="Jejak audit">
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Setiap perubahan stok dan pengiriman dicatat bersama siapa yang melakukannya, dari alamat IP mana, dan di koordinat mana.
            Catatan hanya bisa ditambah, dan saling terkait lewat hash sehingga perubahan diam-diam bisa terdeteksi.
        </p>

        <!-- Integritas -->
        <section class="mt-5 border-y border-line py-4" aria-labelledby="integritas">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="integritas" class="text-sm font-semibold">Pemeriksaan integritas</h2>
                <button type="button" class="rounded-md border border-line px-3 py-1.5 text-sm hover:bg-mist disabled:opacity-60" :disabled="checking" @click="check">
                    {{ checking ? 'Memeriksa…' : 'Periksa ulang' }}
                </button>
            </div>

            <p v-if="integrityError" class="mt-3 text-sm text-quake-3" role="alert">{{ integrityError }}</p>
            <p v-else-if="!integrity" class="mt-3 text-sm text-muted">Memeriksa rantai audit dan saldo stok…</p>

            <dl v-else class="mt-3 grid gap-x-8 gap-y-3 md:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted">Rantai audit</dt>
                    <dd class="mt-1 text-sm font-medium" :class="integrity.audit.ok ? 'text-camp' : 'text-quake-3'" role="status">
                        <template v-if="integrity.audit.ok">Utuh ({{ formatQty(integrity.audit.total) }} catatan)</template>
                        <template v-else>Rusak mulai dari catatan nomor {{ integrity.audit.broken_id }}</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Saldo stok terhadap ledger</dt>
                    <dd class="mt-1 text-sm font-medium" :class="integrity.ledger.ok ? 'text-camp' : 'text-quake-3'" role="status">
                        <template v-if="integrity.ledger.ok">Semua cocok</template>
                        <template v-else>{{ integrity.ledger.mismatches.length }} saldo tidak cocok</template>
                    </dd>
                </div>
            </dl>

            <ul v-if="integrity && !integrity.ledger.ok" class="mt-3 space-y-1 text-sm">
                <li v-for="m in integrity.ledger.mismatches" :key="m.id + m.field" class="border-l-4 border-quake-3 bg-quake-3/5 px-3 py-1.5">
                    {{ m.label }}, {{ m.field }}: saldo {{ m.balance }}, menurut ledger {{ m.ledger }}.
                </li>
            </ul>
        </section>

        <!-- Daftar -->
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <label for="aksi" class="sr-only">Saring menurut aksi</label>
                <select id="aksi" v-model="action" class="rounded-md border border-line bg-surface px-3 py-1.5 text-sm" @change="load(1)">
                    <option value="">Semua aksi</option>
                    <option v-for="(label, key) in actions" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>
            <p class="text-sm text-muted" aria-live="polite">{{ loading ? 'Memuat…' : `${formatQty(meta.total)} catatan` }}</p>
        </div>

        <p v-if="error" class="mt-3 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ error }}</p>

        <div v-if="logs.length" class="mt-3 overflow-x-auto bg-surface">
            <table class="w-full min-w-[56rem] text-sm">
                <thead class="border-b border-line text-left text-xs text-muted">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Waktu</th>
                        <th class="px-4 py-2.5 font-medium">Aksi</th>
                        <th class="px-4 py-2.5 font-medium">Rincian</th>
                        <th class="px-4 py-2.5 font-medium">Pengguna</th>
                        <th class="px-4 py-2.5 font-medium">Lokasi GPS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in logs" :key="l.id" class="border-b border-line align-top last:border-b-0">
                        <td class="whitespace-nowrap px-4 py-2.5 text-muted">{{ formatWib(l.at) }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ l.label }}</td>
                        <td class="max-w-sm px-4 py-2.5 text-muted">{{ l.summary || '–' }}</td>
                        <td class="px-4 py-2.5">
                            <template v-if="l.user">{{ l.user.name }}<span class="block text-xs text-muted">{{ l.ip }}</span></template>
                            <template v-else><span class="text-muted">Sistem</span></template>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-xs text-muted">{{ place(l.location) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else-if="!loading && !error" class="mt-3 border-t border-line py-10 text-sm text-muted">
            <p class="font-medium text-ink">Belum ada catatan.</p>
            <p class="mt-1">Catatan muncul saat ada stok masuk, pengiriman dibuat, dikirim, atau diterima.</p>
        </div>

        <nav v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm" aria-label="Halaman">
            <button type="button" class="rounded-md border border-line px-3 py-1.5 hover:bg-mist disabled:opacity-40" :disabled="meta.current_page <= 1 || loading" @click="load(meta.current_page - 1)">Sebelumnya</button>
            <span class="text-muted">Halaman {{ meta.current_page }} dari {{ meta.last_page }}</span>
            <button type="button" class="rounded-md border border-line px-3 py-1.5 hover:bg-mist disabled:opacity-40" :disabled="meta.current_page >= meta.last_page || loading" @click="load(meta.current_page + 1)">Berikutnya</button>
        </nav>
    </Layout>
</template>
