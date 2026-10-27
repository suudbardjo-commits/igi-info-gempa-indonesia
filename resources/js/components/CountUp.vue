<script setup>
import { onBeforeUnmount, onMounted, ref, toRef } from 'vue';
import { useCountUp } from '../composables/useCountUp';

/** Angka yang menghitung naik saat pertama terlihat di layar. */
const props = defineProps({ value: { type: Number, required: true } });

const el = ref(null);
const { shown, start } = useCountUp(toRef(props, 'value'));
const nf = new Intl.NumberFormat('id-ID');
let observer;

onMounted(() => {
    if (!('IntersectionObserver' in window)) {
        start();

        return;
    }

    observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
            start();
            observer.disconnect();
        }
    });
    observer.observe(el.value);
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <span ref="el">{{ nf.format(shown) }}</span>
</template>
