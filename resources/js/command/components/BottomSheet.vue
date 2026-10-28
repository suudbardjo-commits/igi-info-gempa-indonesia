<script setup>
import { computed, toRef } from 'vue';
import { SNAP_NAMES, useBottomSheet } from '../composables/useBottomSheet';

const props = defineProps({ enabled: { type: Boolean, default: true } });
const sheet = useBottomSheet({ enabled: toRef(props, 'enabled') });

const full = computed(() => sheet.snaps.value[2]);
const label = computed(() => ['Perbesar panel', 'Perbesar penuh', 'Kecilkan panel'][sheet.index.value]);

defineExpose({ height: sheet.height, index: sheet.index, snapTo: sheet.snapTo });
</script>

<template>
    <!-- Tinggi tetap = titik Full; yang tampak diatur transform: translateY memakai --sheet-height (tanpa reflow). -->
    <section class="sheet" :class="{ dragging: sheet.dragging.value }" :style="{ height: `${full}px` }" aria-label="Panel data gempa">
        <button
            type="button"
            class="handle"
            :aria-label="`${label}. Titik saat ini: ${SNAP_NAMES[sheet.index.value]}. Seret atau gunakan panah atas dan bawah.`"
            @pointerdown="sheet.onPointerDown"
            @pointermove="sheet.onPointerMove"
            @pointerup="sheet.onPointerUp"
            @pointercancel="sheet.onPointerUp"
            @keydown="sheet.onKeydown"
        >
            <span class="grip" aria-hidden="true"></span>
        </button>
        <div class="body" :class="{ locked: sheet.index.value === 0 }">
            <slot />
        </div>
    </section>
</template>

<style scoped>
.sheet { position: fixed; z-index: 1100; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; background: #090d16; border-top: 1px solid #374151; border-radius: 4px 4px 0 0; transform: translate3d(0, calc(100% - var(--sheet-height, 220px)), 0); will-change: transform; padding-bottom: env(safe-area-inset-bottom); }
.handle { flex: none; display: flex; align-items: center; justify-content: center; width: 100%; height: 28px; background: transparent; border: 0; cursor: grab; touch-action: none; }
.handle:active { cursor: grabbing; }
.handle:focus-visible { outline: 2px solid #38bdf8; outline-offset: -2px; }
.grip { width: 40px; height: 4px; background: #4b5563; border-radius: 1px; }
.body { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 0 12px 24px; display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; align-content: start; }
/* Titik Peek: isi dipotong (tidak digulir) supaya gestur seret tidak berebut dengan gulir. */
.body.locked { overflow: hidden; }
@media (prefers-reduced-motion: reduce) { .sheet { will-change: auto; } }
</style>
