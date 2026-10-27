<script setup>
import { LEVEL } from '../lib/berita';
import { formatAgo } from '../lib/quake';
import Icon from './Icon.vue';

defineProps({ items: { type: Array, required: true } });

// Ikon dan warna pita per tingkat: info biru, penting kuning, darurat merah.
const STYLE = {
    info: { icon: 'info', bar: 'bg-brand', bg: 'bg-white' },
    penting: { icon: 'warning', bar: 'bg-sun', bg: 'bg-[#fff8e8]' },
    darurat: { icon: 'bell', bar: 'bg-quake-3', bg: 'bg-[#fff0ed]' },
};
</script>

<template>
    <ul v-if="items.length" class="space-y-3" aria-label="Pengumuman resmi">
        <li v-for="a in items" :key="a.id" class="relative overflow-hidden rounded-3xl p-5 pl-7 shadow-sm ring-1 ring-line" :class="STYLE[a.level]?.bg">
            <span class="absolute inset-y-0 left-0 w-2" :class="STYLE[a.level]?.bar" />
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-extrabold" :class="LEVEL[a.level]?.chip"><Icon :name="STYLE[a.level]?.icon ?? 'info'" :size="14" />{{ LEVEL[a.level]?.label }}</span>
                <h3 class="text-lg font-extrabold leading-snug">{{ a.title }}</h3>
            </div>
            <p class="mt-2 whitespace-pre-line text-base leading-relaxed">{{ a.body }}</p>
            <p class="mt-2 text-sm text-muted">Diterbitkan {{ formatAgo(a.published_at) }}</p>
        </li>
    </ul>
</template>
