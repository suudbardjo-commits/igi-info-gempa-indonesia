<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

// Angka yang menghitung naik ke nilai baru. Tanpa animasi bila pengguna meminta gerak dikurangi atau tab tidak terlihat.
const props = defineProps({
    value: { type: Number, default: 0 },
    decimals: { type: Number, default: 0 },
    duration: { type: Number, default: 700 },
});

const shown = ref(0);
let raf = 0;

function fmt(n) {
    return props.decimals ? n.toFixed(props.decimals).replace('.', ',') : Math.round(n).toLocaleString('id-ID');
}

function run(to) {
    cancelAnimationFrame(raf);
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || document.hidden) { shown.value = to; return; }
    const from = shown.value;
    const t0 = performance.now();
    const step = (t) => {
        const k = Math.min(1, (t - t0) / props.duration);
        shown.value = from + (to - from) * (1 - (1 - k) ** 3);
        if (k < 1) raf = requestAnimationFrame(step);
        else shown.value = to;
    };
    raf = requestAnimationFrame(step);
}

watch(() => props.value, (v) => run(v));
if (typeof window !== 'undefined') run(props.value);
onBeforeUnmount(() => cancelAnimationFrame(raf));
</script>

<template><span>{{ fmt(shown) }}</span></template>
