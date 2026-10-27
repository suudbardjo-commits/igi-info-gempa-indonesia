<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { formatMagnitude, magnitudeColor } from '../lib/quake';

const props = defineProps({
    alert: { type: Object, default: null },
});

const emit = defineEmits(['view', 'dismiss']);

let timer;

watch(
    () => props.alert,
    (alert) => {
        clearTimeout(timer);
        if (alert) timer = setTimeout(() => emit('dismiss'), 20000);
    },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="-translate-y-3 opacity-0"
        leave-active-class="transition duration-200 ease-in"
        leave-to-class="opacity-0"
    >
        <div
            v-if="alert"
            class="absolute left-1/2 top-3 z-[1000] w-[min(34rem,calc(100%-1.5rem))] -translate-x-1/2"
            role="alert"
        >
            <div class="flex items-center gap-3 rounded-lg bg-ink p-3 pr-2 text-white shadow-lg">
                <span class="mag text-3xl" :style="{ color: magnitudeColor(alert.magnitude) }">
                    {{ formatMagnitude(alert.magnitude) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">Gempa baru terjadi</p>
                    <p class="truncate text-sm text-white/80">{{ alert.location }}</p>
                </div>
                <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-ink" @click="emit('view')">
                    Lihat di peta
                </button>
                <button
                    type="button"
                    class="rounded-md px-2.5 py-1.5 text-lg leading-none text-white/70 hover:text-white"
                    aria-label="Tutup peringatan"
                    @click="emit('dismiss')"
                >
                    &times;
                </button>
            </div>
        </div>
    </Transition>
</template>
