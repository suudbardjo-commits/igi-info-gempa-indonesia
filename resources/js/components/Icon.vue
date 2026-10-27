<script setup>
// Ikon garis sederhana, digambar sendiri (24x24). Dipakai agar tombol dan menu punya penanda gambar yang cepat dikenali.
defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 24 },
    stroke: { type: Number, default: 2 },
});

const PATHS = {
    home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    map: 'M9 4L3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14',
    news: 'M4 5h13a2 2 0 012 2v12H6a2 2 0 01-2-2V5zM19 9h2v8a2 2 0 01-2 2M8 9h7M8 13h7M8 17h4',
    shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4',
    user: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21c0-4 3.6-7 8-7s8 3 8 7',
    pulse: 'M3 12h4l3-8 4 16 3-8h4',
    bell: 'M6 9a6 6 0 1112 0c0 6 2 7 2 7H4s2-1 2-7zM10 20a2 2 0 004 0',
    pin: 'M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11zM12 12a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
    bag: 'M8 7V6a4 4 0 018 0v1M5 9a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V9zM9 14h6',
    check: 'M5 12l5 5 9-10',
    chevron: 'M9 6l6 6-6 6',
    arrow: 'M5 12h14M13 6l6 6-6 6',
    text: 'M3 19l5-14 5 14M5 14h6M15 19l3-8 3 8M16 17h4',
    info: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 11v6M12 7.5v.01',
    phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z',
    box: 'M3 8l9-5 9 5v11H3V8zM3 8l9 5 9-5M12 13v6',
    external: 'M14 4h6v6M20 4l-9 9M18 14v5H5V6h5',
    wave: 'M2 12c2-4 4-4 6 0s4 4 6 0 4-4 6 0',
    search: 'M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3',
    login: 'M15 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3',
    warning: 'M12 3l10 18H2L12 3zM12 10v5M12 18v.01',
};
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="stroke" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0">
        <path v-if="name === 'play'" d="M7 4l13 8-13 8V4z" fill="currentColor" stroke="none" />
        <path v-else :d="PATHS[name] ?? PATHS.info" />
    </svg>
</template>
