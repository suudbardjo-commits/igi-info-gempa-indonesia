<script setup>
import { computed, ref } from 'vue';
import { formatAgo, formatWib } from '../lib/quake';
import Icon from './Icon.vue';

const props = defineProps({
    items: { type: Array, required: true },
    sources: { type: Array, required: true },
    /** Beranda: ringkas, tanpa penyaring dan tanpa catatan sumber. */
    compact: { type: Boolean, default: false },
});

const only = ref(null);
const shown = computed(() => (only.value ? props.items.filter((i) => i.source === only.value) : props.items));
const stale = computed(() => props.sources.filter((s) => s.stale));

// Warna lencana tiap lembaga: BMKG biru, BNPB jingga, supaya sumbernya langsung dikenali.
const BADGE = { bmkg: 'bg-brand text-white', bnpb: 'bg-[#ff7a2f] text-white' };
const ICON = { bmkg: 'bell', bnpb: 'news' };
</script>

<template>
    <div>
        <div v-if="!compact && sources.length > 1" class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Saring menurut lembaga">
            <button type="button" :aria-pressed="only === null" class="rounded-full px-4 py-2 text-sm font-bold transition-colors" :class="only === null ? 'bg-brand text-white shadow-sm' : 'bg-white text-ink ring-1 ring-line hover:bg-brand-soft'" @click="only = null">Semua</button>
            <button v-for="s in sources" :key="s.key" type="button" :aria-pressed="only === s.key" class="rounded-full px-4 py-2 text-sm font-bold transition-colors" :class="only === s.key ? 'bg-brand text-white shadow-sm' : 'bg-white text-ink ring-1 ring-line hover:bg-brand-soft'" @click="only = s.key">{{ s.label }}</button>
        </div>

        <ul v-if="shown.length" class="grid gap-3 md:grid-cols-2">
            <li v-for="(n, i) in shown" :key="n.id" v-reveal="Math.min(i, 5) * 50">
                <a :href="n.url" target="_blank" rel="noopener noreferrer" class="lift group flex h-full flex-col rounded-3xl bg-white p-5 shadow-sm ring-1 ring-line">
                    <span class="flex flex-wrap items-center gap-2 text-xs font-bold text-muted">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1" :class="BADGE[n.source]"><Icon :name="ICON[n.source] ?? 'news'" :size="14" />{{ n.source_label }}</span>
                        <span>{{ n.kind }}</span>
                        <time :datetime="n.published_at" :title="formatWib(n.published_at)">{{ formatAgo(n.published_at) }}</time>
                    </span>
                    <h3 class="mt-3 text-lg font-extrabold leading-snug">{{ n.title }}</h3>
                    <p v-if="n.summary" class="mt-1.5 text-base leading-relaxed text-muted">{{ n.summary }}</p>
                    <span class="mt-auto inline-flex items-center gap-1.5 pt-4 text-sm font-extrabold text-brand">Baca di situs {{ n.source_label }}<span class="sr-only"> (membuka situs lain)</span><Icon name="external" :size="16" class="transition-transform group-hover:translate-x-0.5" /></span>
                </a>
            </li>
        </ul>
        <p v-else class="rounded-3xl bg-white p-6 text-base text-muted shadow-sm ring-1 ring-line">Belum ada kabar dari BMKG atau BNPB yang tersimpan. Berita diambil otomatis; coba lagi beberapa saat lagi.</p>

        <template v-if="!compact">
            <p class="mt-4 text-sm leading-relaxed text-muted">
                Judul dan kutipan singkat berasal dari
                <template v-for="(s, i) in sources" :key="s.key"
                    ><a :href="s.home" target="_blank" rel="noopener noreferrer" class="font-semibold underline underline-offset-2 hover:text-ink">{{ s.label }} ({{ s.kind.toLowerCase() }})</a
                    ><template v-if="i < sources.length - 1">, </template></template
                >; hak cipta ada pada penerbitnya. Kami tidak menyalin isi lengkap dan tidak berafiliasi dengan lembaga tersebut. Diambil otomatis, jadi bisa tertunda.
            </p>
            <p v-for="s in stale" :key="s.key" class="mt-1 text-sm font-bold text-[#c2410c]" role="status">
                Data {{ s.label }} {{ s.updated_at ? `terakhir diperbarui ${formatAgo(s.updated_at)}` : 'belum berhasil diambil' }}; mungkin tidak mutakhir.
            </p>
        </template>
    </div>
</template>
