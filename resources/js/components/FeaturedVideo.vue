<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from './Icon.vue';

/**
 * Video unggulan dari kanal YouTube "Info BMKG". Saat terlihat di layar, video diputar otomatis tanpa suara dan berulang
 * (suara dinyalakan dari pemutarnya). Otomatis dimatikan bila pengguna memilih "kurangi gerakan" atau mode hemat data;
 * dalam kasus itu video baru dimuat setelah tombol putar ditekan.
 */
const props = defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
});

const box = ref(null);
const playing = ref(false);
let observer;

const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const saveData = navigator.connection?.saveData === true;
const auto = !reduce && !saveData;

// mute=1 wajib agar peramban mengizinkan putar otomatis; playlist=ID agar loop=1 bekerja untuk video tunggal.
const src = (muted) =>
    `https://www.youtube-nocookie.com/embed/${props.id}?autoplay=1&mute=${muted ? 1 : 0}&loop=1&playlist=${props.id}&playsinline=1&rel=0&modestbranding=1`;

const autoSrc = ref(null);

onMounted(() => {
    if (!auto || !('IntersectionObserver' in window)) return;

    observer = new IntersectionObserver(
        ([entry]) => {
            if (entry.isIntersecting) {
                autoSrc.value = src(true);
                observer.disconnect();
            }
        },
        { threshold: 0.45 },
    );
    observer.observe(box.value);
});

onBeforeUnmount(() => observer?.disconnect());

function play() {
    playing.value = true;
    autoSrc.value = src(false);
}
</script>

<template>
    <figure>
        <div ref="box" class="relative aspect-video overflow-hidden rounded-3xl bg-trench shadow-xl ring-1 ring-white/20">
            <iframe v-if="autoSrc" class="absolute inset-0 size-full" :src="autoSrc" :title="title" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen />

            <button v-else type="button" class="group absolute inset-0 size-full text-left" :aria-label="`Putar video: ${title}`" @click="play">
                <img :src="`https://i.ytimg.com/vi/${id}/hqdefault.jpg`" alt="" class="absolute inset-0 size-full object-cover opacity-80 transition-transform duration-700 group-hover:scale-105" loading="lazy" referrerpolicy="no-referrer" />
                <span class="absolute inset-0 bg-gradient-to-t from-trench/90 via-trench/20 to-transparent" />
                <span class="absolute inset-0 grid place-items-center">
                    <span class="sheen relative grid size-20 place-items-center overflow-hidden rounded-full bg-white text-brand shadow-xl transition-transform group-hover:scale-110"><Icon name="play" :size="34" /></span>
                </span>
                <span class="absolute inset-x-0 bottom-0 p-5 text-lg font-extrabold text-white">{{ title }}</span>
            </button>

            <span v-if="autoSrc && !playing" class="pointer-events-none absolute left-3 top-3 rounded-full bg-black/55 px-3 py-1 text-xs font-bold text-white backdrop-blur-sm">Diputar tanpa suara. Ketuk video untuk mengatur suara.</span>
        </div>
        <figcaption class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-white/80">
            <span>Dari kanal Info BMKG di YouTube</span>
            <a :href="`https://www.youtube.com/watch?v=${id}`" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 font-bold underline underline-offset-4 hover:text-trace">Buka di YouTube<Icon name="external" :size="16" /><span class="sr-only"> (situs lain)</span></a>
        </figcaption>
    </figure>
</template>
