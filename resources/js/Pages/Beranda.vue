<script setup>
import { Head } from '@inertiajs/vue3';
import AtlasWorkspace from '../components/AtlasWorkspace.vue';
const props = defineProps({ week: Array, camps: Array, official: Object, announcements: Array });
</script>
<template>
    <Head title="Pantauan Indonesia" />
    <AtlasWorkspace :events="{ type: 'FeatureCollection', features: props.week }" :camps="{ type: 'FeatureCollection', features: props.camps }" :official="official" :announcements="announcements" />
</template>
