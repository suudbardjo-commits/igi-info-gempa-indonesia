<script setup>
import { computed, onMounted, ref } from 'vue';

/**
 * Daftar centang tas siaga. Centangan disimpan di perangkat ini saja (localStorage), tidak dikirim ke server.
 * Penyimpanan dibungkus try/catch: di mode pribadi atau saat dinonaktifkan, daftar tetap bisa dipakai selama halaman terbuka.
 */
const KEY = 'igi:tas-siaga:v1';

const GROUPS = [
    { title: 'Air dan makanan', items: [['air', 'Air minum kemasan'], ['makan', 'Makanan siap makan yang tahan lama (biskuit, makanan kaleng)']] },
    { title: 'Kesehatan', items: [['obat', 'Obat pribadi dan perlengkapan P3K'], ['masker', 'Masker'], ['sabun', 'Perlengkapan kebersihan diri']] },
    { title: 'Penerangan dan komunikasi', items: [['senter', 'Senter dan baterai cadangan'], ['radio', 'Radio kecil bertenaga baterai'], ['power', 'Power bank yang terisi'], ['peluit', 'Peluit untuk memanggil bantuan']] },
    { title: 'Dokumen dan pakaian', items: [['dokumen', 'Salinan dokumen penting dalam plastik kedap air'], ['uang', 'Uang tunai secukupnya'], ['nomor', 'Daftar nomor darurat dan nomor keluarga'], ['baju', 'Pakaian ganti dan selimut tipis']] },
];

const total = GROUPS.reduce((n, g) => n + g.items.length, 0);
const checked = ref(new Set());
const saved = ref(true);

onMounted(() => {
    try {
        checked.value = new Set(JSON.parse(localStorage.getItem(KEY) ?? '[]'));
    } catch {
        saved.value = false;
    }
});

function persist() {
    try {
        localStorage.setItem(KEY, JSON.stringify([...checked.value]));
    } catch {
        saved.value = false;
    }
}

function toggle(id) {
    const next = new Set(checked.value);
    next.has(id) ? next.delete(id) : next.add(id);
    checked.value = next;
    persist();
}

function reset() {
    checked.value = new Set();
    persist();
}

const done = computed(() => checked.value.size);
const pct = computed(() => Math.round((done.value / total) * 100));
</script>

<template>
    <div class="rounded-xl border border-line bg-surface p-5 md:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h3 class="font-display text-3xl leading-none">Tas siaga saya</h3>
            <p class="text-sm font-semibold" aria-live="polite">{{ done }} dari {{ total }} siap</p>
        </div>

        <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-mist" role="progressbar" aria-label="Kesiapan tas siaga" :aria-valuenow="done" aria-valuemin="0" :aria-valuemax="total" :aria-valuetext="`${done} dari ${total} barang siap`">
            <div class="h-full rounded-full bg-camp transition-[width] duration-300" :style="{ width: `${pct}%` }" />
        </div>

        <p v-if="done === total" class="mt-3 border-l-4 border-camp bg-camp/5 px-3 py-2 text-sm" role="status">Tas Anda lengkap. Periksa isinya berkala, terutama makanan dan obat yang bisa kedaluwarsa.</p>

        <div class="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-2">
            <fieldset v-for="g in GROUPS" :key="g.title">
                <legend class="text-sm font-semibold">{{ g.title }}</legend>
                <ul class="mt-1">
                    <li v-for="[id, text] in g.items" :key="id">
                        <label class="flex min-h-11 cursor-pointer items-start gap-3 py-2 text-sm leading-snug">
                            <input type="checkbox" class="mt-0.5 size-5 shrink-0 accent-[#0f766e]" :checked="checked.has(id)" @change="toggle(id)" />
                            <span :class="checked.has(id) ? 'text-muted line-through decoration-muted/60' : ''">{{ text }}</span>
                        </label>
                    </li>
                </ul>
            </fieldset>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
            <p class="text-xs text-muted">{{ saved ? 'Centangan tersimpan di HP atau peramban ini saja, tidak dikirim ke server.' : 'Penyimpanan di perangkat ini tidak tersedia, jadi centangan hilang saat halaman ditutup.' }}</p>
            <button v-if="done" type="button" class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold hover:bg-mist" @click="reset">Kosongkan daftar</button>
        </div>
    </div>
</template>
