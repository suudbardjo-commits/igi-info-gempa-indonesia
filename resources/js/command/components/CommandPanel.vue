<script setup>
import QuakeDetail from './QuakeDetail.vue';
import QuakeList from './QuakeList.vue';
import StatusStrip from './StatusStrip.vue';
import TelemetryPanel from './TelemetryPanel.vue';
import WaveHud from './WaveHud.vue';

// Isi panel (dipakai di sisi kiri desktop dan di dalam bottom sheet ponsel): urutan dari yang paling penting.
defineProps({
    quake: Object,
    isLatest: Boolean,
    quakes: Array,
    feed: { type: Object, required: true },
    sim: { type: Object, required: true },
    telemetry: Object,
    locationStatus: String,
    tilesError: Boolean,
    faultsState: String,
});
const emit = defineEmits(['select', 'back-to-latest', 'request-location', 'clear-location', 'refresh']);
</script>

<template>
    <QuakeDetail :quake="quake" :is-latest="isLatest" @back-to-latest="emit('back-to-latest')" />
    <WaveHud v-if="quake" :sim="sim" :has-user="Boolean(telemetry)" />
    <TelemetryPanel :telemetry="telemetry" :status="locationStatus" @request="emit('request-location')" @clear="emit('clear-location')" />
    <StatusStrip :status="feed.status.value" :error="feed.error.value" :via="feed.via.value" :stale="feed.stale.value" :last-ok-at="feed.lastOkAt.value" :last-check-at="feed.lastCheckAt.value" :tiles-error="tilesError" :faults-state="faultsState" @refresh="emit('refresh')" />
    <QuakeList :quakes="quakes" :active-key="quake?.key" :status="feed.status.value" @select="(k) => emit('select', k)" />
</template>
