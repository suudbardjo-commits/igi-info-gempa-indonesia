<script setup>
import { Head } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';
import Layout from '../../Layouts/LogistikLayout.vue';
import { api, errorMessage } from '../../lib/api';
import { LEVEL } from '../../lib/berita';
import { formatWib } from '../../lib/quake';

const items = ref([]);
const loading = ref(true);
const error = ref(null);
const saving = ref(false);
const form = reactive({ title: '', body: '', level: 'info', expires_in_hours: 24 });

const EXPIRY = [
    { value: 6, label: '6 jam' },
    { value: 24, label: '1 hari' },
    { value: 72, label: '3 hari' },
    { value: 168, label: '7 hari' },
    { value: '', label: 'Tanpa batas' },
];

async function load() {
    loading.value = true;
    error.value = null;

    try {
        items.value = (await api('/announcements/manage')).data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function publish() {
    saving.value = true;
    error.value = null;

    try {
        await api('/announcements', { method: 'POST', body: { ...form, expires_in_hours: form.expires_in_hours || null } });
        form.title = '';
        form.body = '';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function remove(item) {
    if (!window.confirm(`Tarik pengumuman "${item.title}"? Pengumuman hilang dari situs publik.`)) return;

    try {
        await api(`/announcements/${item.id}`, { method: 'DELETE' });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

onMounted(load);
</script>

<template>
    <Head title="Pengumuman" />

    <Layout title="Pengumuman publik">
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Tampil di beranda dan halaman berita sampai kedaluwarsa. Tulis singkat dan faktual; jangan memuat data pribadi atau nomor telepon pribadi.
        </p>

        <form class="mt-5 max-w-2xl space-y-3 border border-line bg-surface p-4" @submit.prevent="publish">
            <label class="block text-sm">
                <span class="font-medium">Judul</span>
                <input v-model="form.title" required minlength="5" maxlength="140" class="mt-1 w-full rounded-md border border-line px-3 py-2" />
            </label>
            <label class="block text-sm">
                <span class="font-medium">Isi</span>
                <textarea v-model="form.body" required minlength="10" maxlength="2000" rows="4" class="mt-1 w-full rounded-md border border-line px-3 py-2" />
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">Tingkat</span>
                    <select v-model="form.level" class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2">
                        <option v-for="(l, key) in LEVEL" :key="key" :value="key">{{ l.label }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Berlaku selama</span>
                    <select v-model="form.expires_in_hours" class="mt-1 w-full rounded-md border border-line bg-surface px-3 py-2">
                        <option v-for="o in EXPIRY" :key="o.label" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
            </div>
            <button type="submit" class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="saving">
                {{ saving ? 'Menerbitkan…' : 'Terbitkan' }}
            </button>
        </form>

        <p v-if="error" class="mt-3 border-l-4 border-quake-3 bg-quake-3/5 px-3 py-2 text-sm" role="alert">{{ error }}</p>

        <h2 class="mt-8 font-display text-lg font-semibold">Riwayat</h2>
        <ul v-if="items.length" class="mt-2 border-t border-line bg-surface">
            <li v-for="a in items" :key="a.id" class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                <div class="min-w-0 text-sm">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 text-xs font-semibold" :class="LEVEL[a.level]?.chip">{{ LEVEL[a.level]?.label }}</span>
                        <span class="font-semibold">{{ a.title }}</span>
                        <span class="text-xs font-medium" :class="a.active ? 'text-camp' : 'text-muted'">{{ a.active ? 'Tayang' : 'Kedaluwarsa' }}</span>
                    </p>
                    <p class="mt-1 whitespace-pre-line text-muted">{{ a.body }}</p>
                    <p class="mt-1 text-xs text-muted">
                        Terbit {{ formatWib(a.published_at) }}<template v-if="a.expires_at">, berakhir {{ formatWib(a.expires_at) }}</template>
                    </p>
                </div>
                <button type="button" class="shrink-0 rounded-md border border-line px-3 py-1.5 text-sm hover:bg-mist" @click="remove(a)">Tarik</button>
            </li>
        </ul>
        <p v-else-if="!loading" class="mt-2 border-t border-line py-8 text-sm text-muted">Belum ada pengumuman.</p>
    </Layout>
</template>
