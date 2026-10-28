import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

export const SNAP_NAMES = ['peek', 'half', 'full'];

/**
 * Bottom sheet dengan tiga titik snap: Peek 220 px, Half 55% layar, Full 88% layar.
 *
 * Kinerja: sheet berukuran tetap setinggi Full dan digeser dengan transform: translateY (tanpa reflow). Tinggi yang
 * tampak ditulis ke CSS variable --sheet-height di :root lewat requestAnimationFrame, sehingga elemen lain (tombol
 * recenter, kontrol peta) ikut bergerak 60 FPS dengan transform yang sama.
 */
export function useBottomSheet({ enabled }) {
    const vh = ref(typeof window === 'undefined' ? 800 : window.innerHeight);
    const snaps = computed(() => [220, Math.round(vh.value * 0.55), Math.round(vh.value * 0.88)]);
    const index = ref(0);
    const height = ref(220);
    const dragging = ref(false);

    let frame = 0;
    let startY = 0;
    let startH = 0;
    let lastY = 0;
    let lastT = 0;
    let velocity = 0; // px/ms, positif = naik
    let tween = 0;

    const write = () => document.documentElement.style.setProperty('--sheet-height', `${enabled.value ? Math.round(height.value) : 0}px`);

    /** Tulis di frame berikutnya (selama seret/animasi, digabung per frame). */
    function publish() {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(write);
    }

    const clamp = (h) => Math.max(snaps.value[0] * 0.9, Math.min(snaps.value[2], h));

    function snapTo(i, animate = true) {
        index.value = Math.max(0, Math.min(2, i));
        const target = snaps.value[index.value];
        cancelAnimationFrame(tween);
        if (!animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            height.value = target;
            write(); // langsung, tanpa menunggu frame (mis. saat pertama dipasang)

            return;
        }
        // Tween ease-out; tiap frame memperbarui --sheet-height.
        const from = height.value;
        const t0 = performance.now();
        const step = (now) => {
            const p = Math.min(1, (now - t0) / 240);
            height.value = from + (target - from) * (1 - (1 - p) ** 3);
            publish();
            if (p < 1) tween = requestAnimationFrame(step);
        };
        tween = requestAnimationFrame(step);
    }

    function onPointerDown(e) {
        if (!enabled.value) return;
        cancelAnimationFrame(tween);
        dragging.value = true;
        startY = lastY = e.clientY;
        startH = height.value;
        lastT = performance.now();
        velocity = 0;
        try { e.currentTarget.setPointerCapture?.(e.pointerId); } catch { /* pointer sintetis atau sudah dilepas */ }
    }

    function onPointerMove(e) {
        if (!dragging.value) return;
        const now = performance.now();
        const dt = Math.max(1, now - lastT);
        velocity = (lastY - e.clientY) / dt;
        lastY = e.clientY;
        lastT = now;
        height.value = clamp(startH + (startY - e.clientY));
        publish();
    }

    function onPointerUp() {
        if (!dragging.value) return;
        dragging.value = false;
        // Lemparan cepat melompat satu titik; selain itu ke titik terdekat dengan ketinggian saat ini.
        let target;
        if (Math.abs(velocity) > 0.6) {
            target = index.value + (velocity > 0 ? 1 : -1);
        } else {
            const h = height.value;
            target = snaps.value.reduce((best, s, i) => (Math.abs(s - h) < Math.abs(snaps.value[best] - h) ? i : best), 0);
        }
        snapTo(target);
    }

    /** Papan ketik: panah atas/bawah mengganti titik snap; Enter/Spasi berputar. */
    function onKeydown(e) {
        if (e.key === 'ArrowUp') { e.preventDefault(); snapTo(index.value + 1); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); snapTo(index.value - 1); }
        else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); snapTo((index.value + 1) % 3); }
    }

    function onResize() {
        vh.value = window.innerHeight;
        snapTo(index.value, false);
    }

    onMounted(() => {
        window.addEventListener('resize', onResize);
        snapTo(0, false);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('resize', onResize);
        cancelAnimationFrame(frame);
        cancelAnimationFrame(tween);
        document.documentElement.style.removeProperty('--sheet-height');
    });

    return { snaps, index, height, dragging, snapTo, onPointerDown, onPointerMove, onPointerUp, onKeydown, publish };
}
