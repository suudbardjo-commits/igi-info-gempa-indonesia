<script setup>
import { Head } from '@inertiajs/vue3';
import AtlasWorkspace from '../components/AtlasWorkspace.vue';
defineProps({ events: Object, camps: Object });
</script>
<template>
    <Head title="Peta pantauan Indonesia" />
    <AtlasWorkspace :events="events" :camps="camps" />
</template>
