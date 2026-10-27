<script setup>
import Icon from './Icon.vue';

/** Kepala halaman yang tenang: judul sedang, keterangan singkat, tanpa gradasi atau gerak dekoratif. Isi tambahan lewat slot. */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    icon: { type: String, default: null },
    tone: { type: String, default: 'blue' },
});
</script>

<template>
    <section class="border-b border-line bg-white">
        <div class="mx-auto max-w-6xl px-4 pb-[4.5rem] pt-8 md:px-6 md:pt-10">
            <span v-if="icon" class="mb-3 inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted"><Icon :name="icon" :size="18" />IGI · Info Gempa Indonesia</span>
            <h1 class="max-w-3xl text-2xl font-semibold leading-tight tracking-tight text-ink md:text-3xl">{{ title }}</h1>
            <p v-if="subtitle" class="mt-2 max-w-2xl text-base leading-relaxed text-muted">{{ subtitle }}</p>
            <div class="mt-5 empty:hidden"><slot /></div>
        </div>
    </section>
</template>
