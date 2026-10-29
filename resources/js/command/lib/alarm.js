// Sirene darurat (Web Audio API, tanpa berkas MP3) dan buletin suara (Web Speech API, id-ID).
// Peramban hanya mengizinkan bunyi setelah gerakan pengguna, maka sistem ini mulai MATI dan diaktifkan lewat tombol di header.

/** Gempa yang memicu sirene: M >= 5,0 atau potensi tsunami disebut resmi. */
export function alertLevelFor(q) {
    if (!q) return null;
    if (tsunamiDanger(q.tsunami)) return 'danger';
    if (q.mag >= 5) return 'warning';

    return null;
}

/** Teks "Potensi" BMKG: "Tidak berpotensi tsunami" aman; selain itu yang menyebut tsunami dianggap bahaya. */
export function tsunamiDanger(text) {
    if (!text) return false;
    const t = String(text).toLowerCase();

    return t.includes('tsunami') && !/(tidak|tak)\s+berpotensi/.test(t);
}

function tsunamiSentence(q) {
    if (!q.tsunami) return 'Status tsunami menunggu informasi resmi BMKG.';

    return tsunamiDanger(q.tsunami) ? `Perhatian, ${q.tsunami}.` : 'Tidak berpotensi tsunami.';
}

/** Kalimat buletin yang dibacakan. Magnitudo dibaca dengan koma desimal; wilayah apa adanya dari sumber. */
export function buildBulletin(q) {
    const mag = Number(q.mag).toFixed(1).replace('.', ',');

    return `Peringatan gempa bumi magnitudo ${mag} di wilayah ${q.region}. ${tsunamiSentence(q)} Segera menuju tempat aman jika getaran terasa kuat.`;
}

/** Parameter sirene per tingkat: 'danger' lebih cepat dan lebih keras. */
export const SIREN = {
    warning: { period: 0.6, gain: 0.18 },
    danger: { period: 0.35, gain: 0.26 },
};
export const MAX_SIREN_SECONDS = 30;

/**
 * Pengendali bunyi. `playSeismicAlarm(durationSec, alertLevel)`: dua OscillatorNode (800 Hz dan 1000 Hz) yang bergantian
 * tiap `period` detik lewat jadwal gain, selesai otomatis setelah durasi (maksimum 30 detik).
 */
export function createAlarm() {
    let ctx = null;
    let active = null; // { stop() }
    let enabled = false;

    function context() {
        const Ctx = globalThis.AudioContext ?? globalThis.webkitAudioContext;
        if (!Ctx) return null;
        ctx ??= new Ctx();

        return ctx;
    }

    /** Dipanggil dari klik pengguna: membuka AudioContext dan memuat suara TTS. */
    async function enable() {
        enabled = true;
        try {
            const c = context();
            if (c?.state === 'suspended') await c.resume();
        } catch { /* tanpa Web Audio */ }
        try { globalThis.speechSynthesis?.getVoices(); } catch { /* tanpa TTS */ }

        return enabled;
    }

    function stop() {
        active?.stop();
        active = null;
        try { globalThis.speechSynthesis?.cancel(); } catch { /* abaikan */ }
    }

    function disable() {
        enabled = false;
        stop();
    }

    function playSeismicAlarm(durationSec = 8, alertLevel = 'warning') {
        if (!enabled) return false;
        const c = context();
        if (!c) return false;
        const { period, gain } = SIREN[alertLevel] ?? SIREN.warning;
        const dur = Math.max(1, Math.min(MAX_SIREN_SECONDS, Number(durationSec) || 8));
        try {
            active?.stop();
            const t0 = c.currentTime + 0.02;
            const master = c.createGain();
            master.gain.setValueAtTime(0, t0);
            master.gain.linearRampToValueAtTime(1, t0 + 0.05);
            master.gain.setValueAtTime(1, t0 + dur - 0.2);
            master.gain.linearRampToValueAtTime(0, t0 + dur);
            master.connect(c.destination);

            const tones = [800, 1000].map((hz) => {
                const osc = c.createOscillator();
                const g = c.createGain();
                osc.type = 'square';
                osc.frequency.value = hz;
                g.gain.value = 0;
                osc.connect(g).connect(master);
                osc.start(t0);
                osc.stop(t0 + dur + 0.05);

                return g;
            });
            // Nada bergantian: tiap `period` detik satu osilator dibuka, yang lain ditutup.
            for (let t = 0, i = 0; t < dur; t += period, i++) {
                tones[i % 2].gain.setValueAtTime(gain, t0 + t);
                tones[(i + 1) % 2].gain.setValueAtTime(0, t0 + t);
            }
            const timer = setTimeout(() => { active = null; }, (dur + 0.2) * 1000);
            active = { stop: () => { clearTimeout(timer); tones.forEach((g) => g.gain.cancelScheduledValues(0)); master.gain.cancelScheduledValues(0); master.gain.setValueAtTime(0, c.currentTime); master.disconnect(); } };

            return true;
        } catch {
            return false;
        }
    }

    function speak(text) {
        const synth = globalThis.speechSynthesis;
        if (!enabled || !synth || typeof globalThis.SpeechSynthesisUtterance === 'undefined') return false;
        try {
            synth.cancel();
            const u = new globalThis.SpeechSynthesisUtterance(text);
            u.lang = 'id-ID';
            const voice = synth.getVoices().find((v) => v.lang?.toLowerCase().replace('_', '-').startsWith('id'));
            if (voice) u.voice = voice;
            u.rate = 0.95;
            synth.speak(u);

            return true;
        } catch {
            return false;
        }
    }

    return { enable, disable, stop, playSeismicAlarm, speak, get enabled() { return enabled; } };
}
