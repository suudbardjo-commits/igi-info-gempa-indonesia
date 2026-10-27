import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Angka yang menghitung naik dari 0 ke nilai akhir saat pertama kali terlihat. Bila nilai berubah kemudian (siaran langsung),
 * angka bergerak halus ke nilai baru. Dengan "kurangi gerakan", angka langsung tampil.
 */
export function useCountUp(target, { duration = 1100 } = {}) {
    const shown = ref(0);
    let raf;
    let started = false;

    const reduce = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    function animate(from, to) {
        cancelAnimationFrame(raf);

        if (reduce || from === to) {
            shown.value = to;

            return;
        }

        const t0 = performance.now();
        const tick = (now) => {
            const p = Math.min(1, (now - t0) / duration);
            const eased = 1 - (1 - p) ** 3;
            shown.value = Math.round(from + (to - from) * eased);
            if (p < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
    }

    /** Dipanggil saat elemennya terlihat (lihat direktif v-reveal). */
    function start() {
        if (started) return;
        started = true;
        animate(0, target.value);
    }

    watch(target, (to, from) => started && animate(from ?? shown.value, to));
    onBeforeUnmount(() => cancelAnimationFrame(raf));

    return { shown, start };
}
