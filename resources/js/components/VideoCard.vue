<script setup>
import { ref } from 'vue';
import Icon from './Icon.vue';

/**
 * Video dari kanal YouTube "Info BMKG" dengan gambar sampul. Pemutarnya baru dimuat setelah gambar sampul diketuk
 * (supaya halaman tetap ringan); gambar sampulnya sendiri diambil dari YouTube.
 */
defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    note: { type: String, default: null },
});

const playing = ref(false);
</script>

<template>
    <figure class="lift group overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-line">
        <div class="relative aspect-video overflow-hidden bg-trench">
            <iframe
                v-if="playing"
                class="absolute inset-0 size-full"
                :src="`https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&playsinline=1`"
                :title="title"
                allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                referrerpolicy="strict-origin-when-cross-origin"
                allowfullscreen
            />

            <button v-else type="button" class="absolute inset-0 size-full text-left" :aria-label="`Putar video: ${title}`" @click="playing = true">
                <img :src="`https://i.ytimg.com/vi/${id}/hqdefault.jpg`" alt="" class="absolute inset-0 size-full object-cover transition-transform duration-700 group-hover:scale-105" loading="lazy" referrerpolicy="no-referrer" />
                <span class="absolute inset-0 bg-gradient-to-t from-trench/70 via-transparent to-transparent" />
                <span class="absolute inset-0 grid place-items-center">
                    <span class="grid size-16 place-items-center rounded-full bg-white text-brand shadow-xl transition-transform group-hover:scale-110"><Icon name="play" :size="28" /></span>
                </span>
            </button>
        </div>
        <figcaption class="p-4">
            <p class="text-lg font-extrabold leading-snug">{{ title }}</p>
            <p v-if="note" class="mt-1 text-sm text-muted">{{ note }}</p>
            <a :href="`https://www.youtube.com/watch?v=${id}`" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1.5 text-sm font-bold text-brand underline underline-offset-4">Buka di YouTube<Icon name="external" :size="14" /><span class="sr-only"> (situs lain)</span></a>
        </figcaption>
    </figure>
</template>
