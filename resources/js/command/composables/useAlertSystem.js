import { onBeforeUnmount, ref } from 'vue';
import { alertLevelFor, buildBulletin, createAlarm } from '../lib/alarm';
import { sameEvent } from '../lib/ingest';

/**
 * Sirene dan buletin suara otomatis. Selalu mulai dalam keadaan MATI (kebijakan autoplay peramban: bunyi hanya setelah
 * gerakan pengguna), jadi pilihan tidak disimpan antar kunjungan. Satu kejadian hanya membunyikan sirene dan buletin satu kali.
 *
 * Alur: onDetected (EMSC, belum divalidasi) membunyikan sirene bila M >= 5; onValidated (BMKG/USGS) membacakan buletin,
 * dan membunyikan sirene bila sebelumnya belum.
 */
export function useAlertSystem() {
    const alarm = createAlarm();
    const enabled = ref(false);
    const speaking = ref(false);
    // Satu kejadian bisa tiba beberapa kali (EMSC lalu BMKG, kunci berbeda): dicocokkan menurut waktu dan lokasi.
    const sirened = [];
    const spoken = [];
    const seen = (list, q) => list.some((e) => e.key === q.key || sameEvent(e, q));

    async function enable() {
        await alarm.enable();
        enabled.value = true;
    }

    function disable() {
        alarm.disable();
        enabled.value = false;
        speaking.value = false;
    }

    const toggle = () => (enabled.value ? disable() : enable());

    /** Uji bunyi singkat supaya pengguna bisa memastikan perangkat bersuara. */
    function test() {
        if (!enabled.value) return false;

        return alarm.playSeismicAlarm(2.5, 'warning');
    }

    function siren(q) {
        const level = alertLevelFor(q);
        if (!level || seen(sirened, q)) return;
        sirened.push(q);
        alarm.playSeismicAlarm(level === 'danger' ? 15 : 8, level);
    }

    /** Gempa terdeteksi EMSC, belum divalidasi: hanya sirene. */
    function detected(q) {
        siren(q);
    }

    /** Data resmi tiba: sirene bila belum, lalu buletin suara. `via` = 'emsc' bila sebelumnya sudah terdeteksi EMSC. */
    function validated(q, via = null) {
        siren(q);
        if (seen(spoken, q) || !(q.mag >= 4.5 || alertLevelFor(q))) return;
        spoken.push(q);
        // Jeda singkat agar sirene tidak menutupi awal ucapan.
        setTimeout(() => {
            speaking.value = alarm.speak(buildBulletin(q));
        }, via === 'emsc' ? 300 : 1500);
    }

    onBeforeUnmount(() => alarm.disable());

    return { enabled, speaking, toggle, enable, disable, test, detected, validated };
}
