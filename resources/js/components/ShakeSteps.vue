<script setup>
// Tiga langkah dasar saat guncangan, digambar sebagai piktogram SVG buatan sendiri.
const STEPS = [
    { key: 'drop', title: 'Merunduk', text: 'Turunkan badan dengan tangan dan lutut sebelum guncangan menjatuhkan Anda.' },
    { key: 'cover', title: 'Berlindung', text: 'Masuk ke bawah meja yang kokoh. Tanpa meja, lindungi kepala dan leher dengan lengan di dekat dinding dalam.' },
    { key: 'hold', title: 'Bertahan', text: 'Pegang kaki meja dan ikuti gerakannya. Tetap di sana sampai guncangan benar-benar berhenti.' },
];
</script>

<template>
    <ol class="grid gap-x-6 gap-y-8 md:grid-cols-3">
        <li v-for="s in STEPS" :key="s.key">
            <svg viewBox="0 0 240 170" class="block w-full rounded-xl border border-line bg-surface" role="img" :aria-label="`Ilustrasi: ${s.title}`">
                <!-- lantai -->
                <line x1="14" y1="150" x2="226" y2="150" stroke="#0e1a2b" stroke-width="3" stroke-linecap="round" />

                <!-- 1. Merunduk: tangan dan lutut, kepala menunduk -->
                <g v-if="s.key === 'drop'" fill="none" stroke="#0e1a2b" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="150" cy="96" r="12" fill="#0e1a2b" stroke="none" />
                    <path stroke-width="10" d="M138 108 L96 110" />
                    <path stroke-width="9" d="M136 112 L142 148" />
                    <path stroke-width="9" d="M98 112 L88 148 L52 148" />
                    <g stroke="#f08a24" stroke-width="3.5">
                        <path d="M60 56 v22 M60 78 l-6-7 M60 78 l6-7" />
                        <path d="M190 40 v24 M190 64 l-6-7 M190 64 l6-7" />
                    </g>
                </g>

                <!-- 2. Berlindung: di bawah meja, lengan menutupi kepala -->
                <g v-else-if="s.key === 'cover'" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="52" y="64" width="136" height="12" rx="3" fill="#14264f" />
                    <path stroke="#14264f" stroke-width="8" d="M64 76 V150 M176 76 V150" />
                    <g stroke="#0e1a2b">
                        <circle cx="132" cy="104" r="11" fill="#0e1a2b" stroke="none" />
                        <path stroke-width="10" d="M112 112 L96 128" />
                        <path stroke-width="9" d="M100 128 L100 148 M106 132 L132 146" />
                        <path stroke-width="7" d="M110 112 L124 96" />
                    </g>
                    <g stroke="#f08a24" stroke-width="3.5">
                        <path d="M30 40 l8 5 -8 5 8 5" />
                        <path d="M210 40 l-8 5 8 5 -8 5" />
                    </g>
                </g>

                <!-- 3. Bertahan: memegang kaki meja; meja bergoyang -->
                <g v-else fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <g class="shake">
                        <rect x="52" y="64" width="136" height="12" rx="3" fill="#14264f" />
                        <path stroke="#14264f" stroke-width="8" d="M64 76 V150 M176 76 V150" />
                    </g>
                    <g stroke="#0e1a2b">
                        <circle cx="116" cy="104" r="11" fill="#0e1a2b" stroke="none" />
                        <path stroke-width="10" d="M100 114 L88 132" />
                        <path stroke-width="9" d="M92 132 L92 148 M98 136 L122 146" />
                        <path stroke-width="7" d="M104 112 L68 108" />
                    </g>
                    <g stroke="#f08a24" stroke-width="3.5">
                        <path d="M26 98 h-10 M20 94 l-5 4 5 4" />
                        <path d="M214 98 h10 M220 94 l5 4 -5 4" />
                    </g>
                </g>
            </svg>

            <h3 class="mt-3 font-display text-3xl leading-none">{{ s.title }}</h3>
            <p class="mt-2 max-w-sm text-sm leading-relaxed text-muted">{{ s.text }}</p>
        </li>
    </ol>
</template>

<style scoped>
.shake {
    animation: shake 0.55s ease-in-out infinite;
}

@keyframes shake {
    0%,
    100% {
        transform: translateX(0);
    }
    25% {
        transform: translateX(-4px);
    }
    75% {
        transform: translateX(4px);
    }
}
</style>
