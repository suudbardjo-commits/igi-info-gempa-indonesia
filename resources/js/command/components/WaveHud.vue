<script setup>
import { computed } from 'vue';
import { fmtDuration, fmtKm } from '../lib/format';
import { SCALES } from '../composables/useWaveSimulation';

const props = defineProps({ sim: { type: Object, required: true }, hasUser: { type: Boolean, default: false } });

const mode = computed(() => (props.sim.live.value ? 'REAL-TIME' : `SIMULASI ×${props.sim.scale.value}`));
const arrived = computed(() => props.sim.etaS.value != null && props.sim.etaS.value <= 0);
</script>

<template>
    <section class="hud" aria-labelledby="hud-h">
        <header>
            <h3 id="hud-h">Gelombang seismik</h3>
            <span class="mode" :class="{ live: sim.live.value }">{{ mode }}</span>
        </header>

        <dl class="grid">
            <div><dt>T+</dt><dd>{{ fmtDuration(sim.t.value) }}</dd></div>
            <div class="p"><dt>P-wave 6,0 km/dtk</dt><dd>{{ fmtKm(sim.rp.value, 0) }}</dd></div>
            <div class="s"><dt>S-wave 3,5 km/dtk</dt><dd>{{ fmtKm(sim.rs.value, 0) }}</dd></div>
        </dl>

        <p class="eta" :class="{ arrived }" role="status" aria-live="off">
            <template v-if="!hasUser"><span class="lbl">ETA gelombang-S</span><span class="hint">aktifkan lokasi untuk menghitung</span></template>
            <template v-else-if="arrived"><span class="lbl">Gelombang-S</span><b>SUDAH TIBA DI LOKASI ANDA</b></template>
            <template v-else><span class="lbl">ETA gelombang-S ke Anda</span><b>{{ fmtDuration(sim.etaS.value) }}</b></template>
        </p>

        <div class="controls">
            <button type="button" class="btn" @click="sim.restartSimulation()">↻ Ulangi</button>
            <button type="button" class="btn" :aria-pressed="sim.running.value" @click="sim.running.value ? sim.pause() : sim.finished.value ? sim.restartSimulation() : sim.play()">{{ sim.running.value ? '❚❚ Jeda' : '▶ Putar' }}</button>
            <div class="scales" role="group" aria-label="Kecepatan simulasi">
                <button v-for="s in SCALES" :key="s" type="button" class="scale" :aria-pressed="!sim.live.value && sim.scale.value === s" @click="sim.setScale(s)">×{{ s }}</button>
            </div>
        </div>
        <p class="note">Model bumi seragam; hasil sebagai ilustrasi, bukan peringatan dini resmi.</p>
    </section>
</template>

<style scoped>
.hud { border: 1px solid #1f2937; background: #111827; border-radius: 2px; padding: 10px 12px; }
header { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
h3 { margin: 0; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; font-weight: 600; }
.mode { font-size: 10px; padding: 1px 6px; border: 1px solid #374151; color: #9ca3af; border-radius: 2px; }
.mode.live { color: #4ade80; border-color: #166534; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 10px 0 0; }
.grid div { min-width: 0; }
dt { font-size: 10px; color: #9ca3af; line-height: 1.3; }
dd { margin: 2px 0 0; font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; color: #f9fafb; }
.p dd { color: #38bdf8; }
.s dd { color: #f87171; }
.eta { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; margin: 10px 0 0; padding: 8px 10px; background: #090d16; border: 1px solid #1f2937; border-radius: 2px; font-size: 12px; }
.eta b { font-size: 20px; font-variant-numeric: tabular-nums; color: #fbbf24; }
.eta.arrived b { color: #f87171; font-size: 13px; }
.lbl { color: #9ca3af; }
.hint { color: #6b7280; font-size: 11px; }
.controls { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 10px; }
.btn, .scale { min-height: 32px; padding: 0 10px; background: #090d16; color: #e5e7eb; border: 1px solid #1f2937; border-radius: 2px; font: inherit; font-size: 12px; cursor: pointer; }
.btn:hover, .scale:hover { background: #1f2937; }
.btn:focus-visible, .scale:focus-visible { outline: 2px solid #38bdf8; outline-offset: 1px; }
.scales { display: flex; margin-left: auto; }
.scale { border-left-width: 0; padding: 0 8px; }
.scale:first-child { border-left-width: 1px; }
.scale[aria-pressed='true'] { background: #0c4a6e; border-color: #38bdf8; color: #e0f2fe; }
.note { margin: 8px 0 0; font-size: 10px; color: #6b7280; line-height: 1.5; }
@media (max-width: 767px) { .btn, .scale { min-height: 44px; } }
</style>
