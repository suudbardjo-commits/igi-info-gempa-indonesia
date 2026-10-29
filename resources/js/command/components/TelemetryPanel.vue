<script setup>
import { fmtKm } from '../lib/format';

defineProps({
    /** { distanceKm, hypoKm, bearing, compass: {nama, singkat}, badge: {kode, label}, mmi: {mmi, roman, label, body, action} | null } atau null tanpa lokasi */
    telemetry: { type: Object, default: null },
    status: { type: String, default: 'idle' },
});
const emit = defineEmits(['request', 'clear']);

const mmiTone = (n) => (n <= 3 ? 'ok' : n <= 5 ? 'mid' : n <= 7 ? 'high' : 'bad');

const messages = {
    denied: 'Izin lokasi ditolak. Aktifkan izin lokasi di pengaturan peramban lalu coba lagi.',
    unavailable: 'Lokasi perangkat tidak dapat ditentukan.',
    timeout: 'Pencarian lokasi terlalu lama. Coba lagi di tempat terbuka.',
};
</script>

<template>
    <section class="tele" aria-labelledby="tele-h">
        <header><h3 id="tele-h">Telemetri jarak</h3></header>

        <template v-if="telemetry">
            <p class="badge" :class="telemetry.badge.kode" role="status">{{ telemetry.badge.label }}</p>
            <dl class="grid">
                <div><dt>Jarak episentral</dt><dd>{{ fmtKm(telemetry.distanceKm) }}</dd></div>
                <div><dt>Jarak hiposentral</dt><dd>{{ fmtKm(telemetry.hypoKm) }}</dd></div>
                <div><dt>Arah ke episentrum</dt><dd>{{ telemetry.compass.singkat }} <span class="deg">{{ Math.round(telemetry.bearing) }}°</span></dd></div>
            </dl>
            <div v-if="telemetry.mmi" class="mmi" :class="mmiTone(telemetry.mmi.mmi)" role="status">
                <p class="mmi-top"><b class="roman">MMI {{ telemetry.mmi.roman }}</b><span>{{ telemetry.mmi.label }}</span></p>
                <p class="mmi-body">{{ telemetry.mmi.body }}</p>
                <p class="mmi-act"><b>Tindakan:</b> {{ telemetry.mmi.action }}</p>
                <p class="note">Estimasi IGI di lokasi Anda dari magnitudo, kedalaman, dan jarak hiposentrum. Bukan peta guncangan resmi BMKG.</p>
            </div>
            <p class="note">Arah dari lokasi Anda: {{ telemetry.compass.nama }}. Lokasi hanya di memori halaman ini, tidak disimpan.</p>
            <button type="button" class="link" @click="emit('clear')">Hapus lokasi saya</button>
        </template>

        <template v-else>
            <p class="note">Hitung jarak, arah, dan waktu tiba gelombang ke lokasi Anda. Lokasi hanya dipakai saat tombol ditekan dan tidak disimpan.</p>
            <button type="button" class="btn" :disabled="status === 'asking'" @click="emit('request')">{{ status === 'asking' ? 'Mencari lokasi…' : '⌖ Aktifkan lokasi saya' }}</button>
            <p v-if="messages[status]" class="err" role="alert">{{ messages[status] }}</p>
        </template>
    </section>
</template>

<style scoped>
.tele { border: 1px solid #1f2937; background: #111827; border-radius: 2px; padding: 10px 12px; }
h3 { margin: 0; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; font-weight: 600; }
.badge { margin: 8px 0 0; padding: 4px 8px; font-size: 12px; font-weight: 700; border: 1px solid; border-radius: 2px; display: inline-block; }
.badge.bahaya { color: #fecaca; background: #450a0a; border-color: #ef4444; }
.badge.terasa { color: #fed7aa; background: #431407; border-color: #f97316; }
.badge.luar { color: #bbf7d0; background: #052e16; border-color: #22c55e; }
.grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 10px 0 0; }
dt { font-size: 10px; color: #9ca3af; line-height: 1.3; }
dd { margin: 2px 0 0; font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; color: #f9fafb; }
.deg { color: #9ca3af; font-weight: 400; font-size: 11px; }
.mmi { margin: 10px 0 0; padding: 8px 10px; border: 1px solid; border-left-width: 4px; border-radius: 2px; background: #090d16; }
.mmi.ok { border-color: #22c55e; }
.mmi.mid { border-color: #eab308; }
.mmi.high { border-color: #f97316; }
.mmi.bad { border-color: #ef4444; }
.mmi p { margin: 0; }
.mmi-top { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.roman { font-size: 20px; color: #f9fafb; }
.mmi-top span { font-size: 12px; font-weight: 700; color: #e5e7eb; }
.mmi-body { margin-top: 4px !important; font-size: 12px; color: #d1d5db; line-height: 1.5; }
.mmi-act { margin-top: 6px !important; font-size: 12px; color: #fde68a; line-height: 1.5; }
.note { margin: 8px 0 0; font-size: 11px; color: #9ca3af; line-height: 1.5; }
.btn { margin-top: 8px; min-height: 36px; padding: 0 12px; background: #090d16; color: #e5e7eb; border: 1px solid #38bdf8; border-radius: 2px; font: inherit; font-size: 12px; cursor: pointer; }
.btn:hover:not(:disabled) { background: #0c4a6e; }
.btn:disabled { opacity: .6; cursor: wait; }
.btn:focus-visible, .link:focus-visible { outline: 2px solid #38bdf8; outline-offset: 2px; }
.link { margin-top: 6px; min-height: 32px; background: none; border: 0; color: #38bdf8; font: inherit; font-size: 11px; text-decoration: underline; cursor: pointer; padding: 0; }
.err { margin: 8px 0 0; color: #fca5a5; font-size: 11px; line-height: 1.5; }
@media (max-width: 767px) { .btn, .link { min-height: 44px; } }
</style>
