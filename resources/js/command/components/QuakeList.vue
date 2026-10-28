<script setup>
import { fmtAgo } from '../lib/format';
import { magnitudeColor } from '../lib/seismic';

defineProps({ quakes: { type: Array, default: () => [] }, activeKey: { type: String, default: null }, status: { type: String, default: 'loading' } });
const emit = defineEmits(['select']);
</script>

<template>
    <section class="list" aria-labelledby="list-h">
        <header><h3 id="list-h">Gempa terkini ({{ quakes.length }})</h3></header>
        <p v-if="!quakes.length" class="empty">{{ status === 'loading' ? 'Memuat daftar…' : 'Tidak ada data yang dapat ditampilkan. Tidak adanya data bukan berarti tidak ada gempa.' }}</p>
        <ul v-else>
            <li v-for="q in quakes" :key="q.key">
                <button type="button" :class="{ active: q.key === activeKey }" :aria-current="q.key === activeKey ? 'true' : undefined" @click="emit('select', q.key)">
                    <b class="mag" :style="{ color: magnitudeColor(q.mag) }">{{ q.mag.toFixed(1) }}</b>
                    <span class="txt"><span class="region">{{ q.region }}</span><span class="meta">{{ fmtAgo(q.time) }} · {{ q.depthKm != null ? `${q.depthKm} km` : 'kedalaman -' }}<template v-if="q.mmi"> · MMI {{ q.mmi }}</template></span></span>
                </button>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.list { border: 1px solid #1f2937; background: #111827; border-radius: 2px; }
header { padding: 10px 12px 6px; }
h3 { margin: 0; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; font-weight: 600; }
ul { list-style: none; margin: 0; padding: 0; }
li + li { border-top: 1px solid #1f2937; }
button { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 48px; padding: 6px 12px; background: transparent; border: 0; color: inherit; font: inherit; text-align: left; cursor: pointer; }
button:hover { background: #1f2937; }
button.active { background: #0c2a3d; box-shadow: inset 2px 0 0 #38bdf8; }
button:focus-visible { outline: 2px solid #38bdf8; outline-offset: -2px; }
.mag { flex: none; width: 38px; font-size: 18px; font-variant-numeric: tabular-nums; }
.txt { display: flex; flex-direction: column; min-width: 0; }
.region { font-size: 12px; color: #f3f4f6; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.meta { font-size: 11px; color: #9ca3af; font-variant-numeric: tabular-nums; }
.empty { margin: 0; padding: 4px 12px 12px; font-size: 12px; color: #9ca3af; line-height: 1.5; }
@media (max-width: 767px) { button { min-height: 52px; } }
</style>
