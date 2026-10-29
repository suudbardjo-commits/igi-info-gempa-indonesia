<script setup>
import FeltReport from './FeltReport.vue';
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
    sources: Object,
    blackout: Boolean,
    felt: { type: Object, required: true },
    hasLocation: Boolean,
});
const emit = defineEmits(['select', 'back-to-latest', 'request-location', 'clear-location', 'refresh', 'report']);
</script>

<template>
    <QuakeDetail :quake="quake" :is-latest="isLatest" :blackout="blackout" @back-to-latest="emit('back-to-latest')" />
    <WaveHud v-if="quake && !blackout" :sim="sim" :has-user="Boolean(telemetry)" />
    <TelemetryPanel :telemetry="telemetry" :status="locationStatus" @request="emit('request-location')" @clear="emit('clear-location')" />
    <FeltReport :has-location="hasLocation" :sending="felt.sending.value" :result="felt.result.value" :queued="felt.queued.value" :cooldown-seconds="felt.cooldownSeconds.value" :count="felt.reports.value.length" :load-error="felt.loadError.value" @report="(lv) => emit('report', lv)" />
    <StatusStrip :sources="sources" :status="feed.status.value" :error="feed.error.value" :via="feed.via.value" :stale="feed.stale.value" :last-ok-at="feed.lastOkAt.value" :last-check-at="feed.lastCheckAt.value" :tiles-error="tilesError" :faults-state="faultsState" @refresh="emit('refresh')" />
    <QuakeList v-if="!blackout" :quakes="quakes" :active-key="quake?.key" :status="feed.status.value" @select="(k) => emit('select', k)" />
</template>
