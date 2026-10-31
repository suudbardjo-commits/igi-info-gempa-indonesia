/**
 * Direktif v-reveal: elemen muncul halus saat pertama kali tergulir ke layar. Nilai opsional = jeda dalam milidetik
 * (untuk berurutan). Tanpa IntersectionObserver, atau dengan "kurangi gerakan", elemen langsung tampil.
 */
const reduce = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

let observer;

function getObserver() {
    observer ??= new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                // Elemen setinggi layar cukup terlihat 120 px; rasio 12% saja tidak pernah tercapai oleh elemen yang sangat tinggi.
                if (!entry.isIntersecting || (entry.intersectionRatio < 0.12 && entry.intersectionRect.height < 120)) continue;
                entry.target.classList.add('in');
                observer.unobserve(entry.target);
            }
        },
        { threshold: Array.from({ length: 51 }, (_, i) => i / 50), rootMargin: '0px 0px -6% 0px' },
    );

    return observer;
}

export const reveal = {
    mounted(el, binding) {
        if (binding.value) el.style.setProperty('--d', `${binding.value}ms`);

        if (reduce() || !('IntersectionObserver' in window)) {
            el.classList.add('in');

            return;
        }

        el.classList.add('reveal');
        getObserver().observe(el);
    },
    unmounted(el) {
        observer?.unobserve(el);
    },
};
