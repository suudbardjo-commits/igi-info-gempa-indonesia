<script setup>
// Penampang tanah: gelombang merambat dari pusat gempa ke permukaan. Animasi berulang; dengan "kurangi gerakan"
// aktif, gambar diam dengan lingkaran gelombang pada posisi tengah.
</script>

<template>
    <figure>
        <svg viewBox="0 0 400 250" class="block w-full rounded-xl bg-trench" role="img" aria-label="Animasi penampang tanah: gelombang P dan S merambat dari pusat gempa ke permukaan dan mengguncang bangunan">
            <defs>
                <clipPath id="ground"><rect x="0" y="96" width="400" height="154" /></clipPath>
            </defs>

            <rect x="0" y="96" width="400" height="154" fill="#14264f" />
            <path d="M0 96 H400" stroke="#6fe0ee" stroke-opacity="0.5" stroke-width="1.5" />

            <g clip-path="url(#ground)" fill="none">
                <circle class="wave-ring p" cx="200" cy="206" r="180" stroke="#6fe0ee" stroke-width="3" vector-effect="non-scaling-stroke" />
                <circle class="wave-ring p d2" cx="200" cy="206" r="180" stroke="#6fe0ee" stroke-width="3" vector-effect="non-scaling-stroke" />
                <circle class="wave-ring s" cx="200" cy="206" r="180" stroke="#f08a24" stroke-width="3.5" vector-effect="non-scaling-stroke" />
            </g>

            <!-- garis kedalaman -->
            <path d="M200 96 V198" stroke="#fff" stroke-opacity="0.45" stroke-dasharray="3 5" />

            <!-- bangunan di permukaan, bergoyang saat gelombang tiba -->
            <g class="building">
                <rect x="184" y="62" width="32" height="34" fill="#e9eef6" />
                <path d="M180 62 L200 46 L220 62 Z" fill="#a3123a" />
                <rect x="194" y="76" width="12" height="20" fill="#14264f" />
            </g>

            <circle cx="200" cy="206" r="6" fill="#e5412d" stroke="#fff" stroke-width="2" />

            <g font-size="12.5" font-weight="600" fill="#fff">
                <text x="212" y="212">Pusat gempa (hiposenter)</text>
                <text x="226" y="88" fill="#cfe0f5">Episenter</text>
                <text x="206" y="154" fill="#cfe0f5" font-weight="500">Kedalaman</text>
                <g transform="translate(14 26)">
                    <line x1="0" y1="0" x2="20" y2="0" stroke="#6fe0ee" stroke-width="3" />
                    <text x="28" y="4">Gelombang P: tiba lebih dulu</text>
                    <line x1="0" y1="20" x2="20" y2="20" stroke="#f08a24" stroke-width="3.5" />
                    <text x="28" y="24">Gelombang S: menyusul, lebih kuat</text>
                </g>
            </g>
        </svg>
        <figcaption class="mt-2 text-xs text-muted">
            Gambar skematis, bukan skala. Episenter adalah titik di permukaan tepat di atas pusat gempa. Gelombang P merambat lebih cepat daripada S.
        </figcaption>
    </figure>
</template>

<style scoped>
.wave-ring {
    transform-box: fill-box;
    transform-origin: center;
    opacity: 0;
    animation: ring-p 3s linear infinite;
}

.wave-ring.d2 {
    animation-delay: 1.5s;
}

.wave-ring.s {
    animation: ring-s 3s linear infinite;
}

@keyframes ring-p {
    0% {
        transform: scale(0.03);
        opacity: 0.95;
    }
    100% {
        transform: scale(1);
        opacity: 0;
    }
}

@keyframes ring-s {
    0% {
        transform: scale(0.02);
        opacity: 0.95;
    }
    100% {
        transform: scale(0.55);
        opacity: 0;
    }
}

.building {
    transform-box: fill-box;
    transform-origin: 50% 100%;
    animation: tremor 3s linear infinite;
}

/* Bangunan baru bergetar ketika gelombang yang merambat sudah mencapai permukaan (sekitar sepertiga siklus). */
@keyframes tremor {
    0%,
    30% {
        transform: translateX(0);
    }
    34% {
        transform: translateX(-2.5px);
    }
    38% {
        transform: translateX(2.5px);
    }
    42% {
        transform: translateX(-2px);
    }
    46% {
        transform: translateX(2px);
    }
    52%,
    100% {
        transform: translateX(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .wave-ring {
        animation: none;
        transform: scale(0.45);
        opacity: 0.7;
    }

    .wave-ring.d2 {
        display: none;
    }

    .wave-ring.s {
        transform: scale(0.25);
    }

    .building {
        animation: none;
    }
}
</style>
