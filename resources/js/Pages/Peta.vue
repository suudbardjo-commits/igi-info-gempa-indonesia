<script setup>
import Beranda from './Beranda.vue';

// /peta memakai tampilan yang sama dengan beranda; data awal berbentuk FeatureCollection.
defineProps({ events: Object, camps: Object });
</script>
<template>
    <Beranda :week="events?.features ?? []" :camps="camps?.features ?? []" />
</template>
