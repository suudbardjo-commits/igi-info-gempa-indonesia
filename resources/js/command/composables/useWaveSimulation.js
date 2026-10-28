import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { VP, VS, arrivalSeconds, surfaceRadiusKm } from '../lib/seismic';

const LIVE_MAX_AGE_S = 600; // gempa lebih baru dari ini dijalankan real-time dari waktu kejadiannya
const MAX_KM = 800; // simulasi berhenti setelah gelombang-S melewati radius ini (dan titik pengguna)
export const SCALES = [1, 5, 10, 30];

/**
 * Simulasi muka gelombang-P dan -S dari satu gempa. Waktu simulasi `t` (detik sejak gempa) maju lewat
 * requestAnimationFrame. Gempa < 10 menit berjalan real-time dari umurnya; gempa lama diputar ulang dari t=0 dengan skala.
 *
 * @param quake  ref ke kejadian terpilih ({ time, depthKm, ... })
 * @param userDistanceKm  ref jarak episentral ke pengguna (km) atau null
 */
export function useWaveSimulation(quake, userDistanceKm) {
    const t = ref(0);
    const scale = ref(10);
    const running = ref(false);
    const live = ref(false);

    let raf = 0;
    let last = 0;

    const depth = computed(() => quake.value?.depthKm ?? 10);
    const rp = computed(() => surfaceRadiusKm(VP, t.value, depth.value));
    const rs = computed(() => surfaceRadiusKm(VS, t.value, depth.value));

    // Kapan simulasi selesai: S-wave melewati MAX_KM, atau tiba di pengguna (yang lebih lama), dibatasi 20 menit.
    const tEnd = computed(() => {
        const toMax = arrivalSeconds(VS, MAX_KM, depth.value);
        const toUser = userDistanceKm.value != null ? arrivalSeconds(VS, userDistanceKm.value, depth.value) + 5 : 0;

        return Math.min(1200, Math.max(toMax, toUser));
    });
    const finished = computed(() => t.value >= tEnd.value);

    /** Detik simulasi sampai gelombang-S tiba di pengguna; <=0 berarti sudah tiba; null tanpa lokasi. */
    const etaS = computed(() => (userDistanceKm.value == null ? null : arrivalSeconds(VS, userDistanceKm.value, depth.value) - t.value));
    const etaP = computed(() => (userDistanceKm.value == null ? null : arrivalSeconds(VP, userDistanceKm.value, depth.value) - t.value));

    function frame(now) {
        if (!running.value) return;
        const dt = Math.min(0.1, (now - last) / 1000); // batasi lonjakan saat tab sempat tersembunyi
        last = now;
        t.value = Math.min(tEnd.value, t.value + dt * (live.value ? 1 : scale.value));
        if (t.value >= tEnd.value) {
            running.value = false;

            return;
        }
        raf = requestAnimationFrame(frame);
    }

    function play() {
        cancelAnimationFrame(raf);
        running.value = true;
        last = performance.now();
        raf = requestAnimationFrame(frame);
    }

    function pause() {
        running.value = false;
        cancelAnimationFrame(raf);
    }

    /** Mulai dari awal: real-time bila gempa baru, selain itu putar ulang dari t=0. */
    function replay() {
        const q = quake.value;
        if (!q) return;
        const age = (Date.now() - q.time.getTime()) / 1000;
        live.value = age >= 0 && age < LIVE_MAX_AGE_S;
        t.value = live.value ? age : 0;
        play();
    }

    /** Ulang dari t=0 sebagai simulasi (bukan real-time), mis. tombol "Ulangi". */
    function restartSimulation() {
        live.value = false;
        t.value = 0;
        play();
    }

    function setScale(s) {
        scale.value = s;
        live.value = false;
    }

    watch(() => quake.value?.key, () => {
        const q = quake.value;
        pause();
        t.value = 0;
        if (q) replay();
    }, { immediate: true });

    onBeforeUnmount(pause);

    return { t, tEnd, rp, rs, etaS, etaP, scale, running, live, finished, replay, restartSimulation, setScale, pause, play, VP, VS };
}
