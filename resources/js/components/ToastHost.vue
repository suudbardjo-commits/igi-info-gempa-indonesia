<script setup>
import { useToast } from '../composables/useToast';

const { toasts, dismiss } = useToast();
</script>

<template>
    <!-- Di tengah atas supaya tidak menutupi tombol aksi di footer dialog atau panel samping. -->
    <div class="fixed left-1/2 top-4 z-[60] flex w-[min(26rem,calc(100vw-2rem))] -translate-x-1/2 flex-col gap-2" aria-live="polite">
        <div
            v-for="t in toasts"
            :key="t.id"
            class="flex items-start gap-3 rounded-lg border-l-4 bg-ink px-4 py-3 text-sm text-white shadow-lg"
            :class="{
                'border-camp': t.type === 'success',
                'border-quake-3': t.type === 'error' || t.type === 'alert',
                'border-tsunami': t.type === 'info',
            }"
            :role="t.type === 'error' || t.type === 'alert' ? 'alert' : 'status'"
        >
            <p class="flex-1">{{ t.message }}</p>
            <button type="button" class="text-white/60 hover:text-white" aria-label="Tutup pesan" @click="dismiss(t.id)">&times;</button>
        </div>
    </div>
</template>
