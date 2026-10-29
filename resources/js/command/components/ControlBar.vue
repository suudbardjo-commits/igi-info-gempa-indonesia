<script setup>
// Kontrol header: sirene dan suara darurat (mute/unmute), uji bunyi, dan sakelar Mode Blackout.
defineProps({ soundOn: Boolean, speaking: Boolean, blackout: Boolean });
const emit = defineEmits(['toggle-sound', 'test-sound', 'toggle-blackout']);
</script>

<template>
    <div class="ctl" role="group" aria-label="Kontrol peringatan darurat">
        <button type="button" class="b" :class="{ on: soundOn }" :aria-pressed="soundOn" @click="emit('toggle-sound')">
            <span class="dot" aria-hidden="true"></span>{{ soundOn ? 'Suara aktif' : 'Suara mati' }}
        </button>
        <button v-if="soundOn" type="button" class="b ghost" @click="emit('test-sound')">Uji bunyi</button>
        <button type="button" class="b" :class="{ black: blackout }" :aria-pressed="blackout" @click="emit('toggle-blackout')">
            {{ blackout ? 'Blackout aktif' : 'Mode Blackout' }}
        </button>
        <p v-if="speaking" class="sr-only" role="status">Buletin suara sedang dibacakan.</p>
    </div>
</template>

<style scoped>
.ctl { display: flex; flex-wrap: wrap; gap: 6px; }
.b { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; padding: 0 10px; background: #111827; color: #d1d5db; border: 1px solid #374151; border-radius: 2px; font: inherit; font-size: 11px; letter-spacing: .04em; text-transform: uppercase; cursor: pointer; }
.b:hover { border-color: #38bdf8; color: #fff; }
.b:focus-visible { outline: 2px solid #38bdf8; outline-offset: 2px; }
.b.on { border-color: #22c55e; color: #bbf7d0; }
.b.ghost { color: #9ca3af; }
.b.black { background: #000; border-color: #f59e0b; color: #fbbf24; }
.dot { width: 8px; height: 8px; border-radius: 50%; background: #6b7280; }
.on .dot { background: #22c55e; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
@media (max-width: 767px) { .b { min-height: 40px; } }
</style>
