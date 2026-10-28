<script setup>
import { computed, ref } from 'vue';
import { magnitudeColor } from '../lib/seismic';

const props = defineProps({
    /** Kejadian (terbaru dahulu). Hanya sebagian teratas yang ditayangkan. */
    quakes: { type: Array, default: () => [] },
    status: { type: String, default: 'loading' },
    activeKey: { type: String, default: null },
});
const emit = defineEmits(['select']);

const userPaused = ref(false); // tombol jeda (WCAG 2.2.2: gerak otomatis harus bisa dihentikan)
const touching = ref(false);
let touchTimer;

const items = computed(() => props.quakes.slice(0, 10));
// Kecepatan konstan (~9 dtk per kejadian) supaya teks mudah dibaca berapa pun jumlahnya.
const duration = computed(() => `${Math.max(30, items.value.length * 9)}s`);

const paused = computed(() => userPaused.value || touching.value);

function onTouchStart() {
    clearTimeout(touchTimer);
    touching.value = true;
}
function onTouchEnd() {
    // Tetap berhenti sejenak setelah jari diangkat supaya ketukan pada berita sempat diproses.
    touchTimer = setTimeout(() => { touching.value = false; }, 1800);
}

const fmtTime = (d) => new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta', hour12: false }).format(d).replace(':', '.');
</script>

<template>
    <div class="ticker" role="region" aria-label="Berita gempa berjalan" @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd" @touchcancel.passive="onTouchEnd">
        <span class="badge" :class="{ down: status === 'error' }">
            <span class="badge-full">{{ status === 'error' ? 'TERTUNDA' : 'BREAKING' }}</span>
            <span class="badge-icon" aria-hidden="true">⚡</span>
            <span class="sr-only">{{ status === 'error' ? 'Data tertunda' : 'Berita terbaru' }}</span>
        </span>

        <div class="viewport" :class="{ paused }">
            <p v-if="!items.length" class="empty">{{ status === 'loading' ? 'Menghubungi BMKG InaTEWS…' : 'Belum ada data gempa yang dapat ditampilkan.' }}</p>
            <ul v-else class="track" :style="{ '--dur': duration }">
                <template v-for="copy in 2" :key="copy">
                    <li v-for="q in items" :key="`${copy}-${q.key}`" :aria-hidden="copy === 2 ? 'true' : undefined">
                        <button type="button" class="item" :class="{ active: q.key === activeKey }" :tabindex="copy === 2 ? -1 : 0" @click="emit('select', q.key)">
                            <b class="mag" :style="{ color: magnitudeColor(q.mag) }">M{{ q.mag.toFixed(1) }}</b>
                            <span class="txt">{{ q.region }}</span>
                            <span class="dim">{{ fmtTime(q.time) }} WIB</span>
                            <span class="dim">{{ q.depthKm != null ? `${q.depthKm} km` : 'kedalaman -' }}</span>
                            <span v-if="q.tsunami" class="tsu" :class="{ warn: /berpotensi tsunami/i.test(q.tsunami) && !/tidak/i.test(q.tsunami) }">{{ q.tsunami }}</span>
                            <span v-if="q.mmi" class="dim">MMI {{ q.mmi }}</span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>

        <button type="button" class="pause" :aria-pressed="userPaused" :aria-label="userPaused ? 'Lanjutkan teks berjalan' : 'Jeda teks berjalan'" @click="userPaused = !userPaused">{{ userPaused ? '▶' : '❚❚' }}</button>
    </div>
</template>

<style scoped>
.ticker { position: absolute; z-index: 1200; inset: 0 0 auto 0; height: 32px; display: flex; align-items: stretch; background: #090d16; border-bottom: 1px solid #1f2937; font: 12px/32px 'JetBrains Mono Variable', ui-monospace, monospace; color: #e5e7eb; }
.badge { flex: none; min-width: 32px; padding: 0 10px; background: #dc2626; color: #fff; font-weight: 700; letter-spacing: .08em; text-align: center; }
.badge.down { background: #b45309; }
.badge-icon { display: none; }
.viewport { position: relative; flex: 1; min-width: 0; overflow: hidden; }
.track { display: inline-flex; margin: 0; padding: 0; list-style: none; white-space: nowrap; animation: scroll var(--dur) linear infinite; will-change: transform; }
.viewport:hover .track, .viewport:focus-within .track, .viewport.paused .track { animation-play-state: paused; }
.item { display: inline-flex; align-items: center; gap: 10px; height: 32px; padding: 0 22px; border-right: 1px solid #1f2937; background: transparent; color: inherit; font: inherit; cursor: pointer; }
.item:hover, .item.active { background: #111827; }
.item:focus-visible { outline: 2px solid #38bdf8; outline-offset: -2px; }
.mag { font-weight: 700; font-variant-numeric: tabular-nums; }
.dim { color: #9ca3af; font-variant-numeric: tabular-nums; }
.tsu { color: #9ca3af; }
.tsu.warn { color: #f87171; font-weight: 700; }
.empty { margin: 0; padding: 0 14px; color: #9ca3af; }
.pause { flex: none; width: 36px; background: transparent; border: 0; border-left: 1px solid #1f2937; color: #9ca3af; font-size: 10px; cursor: pointer; }
.pause:hover { color: #fff; background: #111827; }
.pause:focus-visible { outline: 2px solid #38bdf8; outline-offset: -2px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
@keyframes scroll { from { transform: translateX(0); } to { transform: translateX(-50%); } }
@media (max-width: 767px) {
    .badge-full { display: none; }
    .badge-icon { display: inline; }
    .badge { padding: 0 8px; font-size: 14px; }
    .pause { width: 44px; }
}
/* Tanpa gerak: daftar tetap bisa digulir manual. */
@media (prefers-reduced-motion: reduce) {
    .viewport { overflow-x: auto; }
    .track { animation: none; }
}
</style>
