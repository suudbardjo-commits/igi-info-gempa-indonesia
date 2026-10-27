<script setup>
// Lambang IGI (heksagon dengan titik teal), dipotong dari logo resmi yang diberikan pemilik (25 Okt 2026).
defineProps({ size: { type: Number, default: 32 } });
</script>

<template>
    <img src="/images/igi-mark.png" :height="size" :style="{ height: `${size}px`, width: 'auto' }" alt="IGI" decoding="async" />
</template>
