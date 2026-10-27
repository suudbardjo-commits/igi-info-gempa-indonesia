<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Icon from '../components/Icon.vue';
import PageHero from '../components/PageHero.vue';
import PrepChecklist from '../components/PrepChecklist.vue';
import ShakeSteps from '../components/ShakeSteps.vue';
import VideoCard from '../components/VideoCard.vue';
import WaveExplainer from '../components/WaveExplainer.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';

const TABS = [
    { key: 'saat', label: 'Saat guncangan', icon: 'pulse', tone: 'bg-coral' },
    {
        key: 'sesudah',
        label: 'Setelah berhenti',
        icon: 'check',
        tone: 'bg-camp',
        steps: [
            'Periksa diri dan orang di sekitar. Beri pertolongan pertama bila perlu.',
            'Keluar dengan tenang lewat tangga, lalu menuju tempat terbuka.',
            'Matikan listrik dan gas bila mencium bau gas. Jangan menyalakan api.',
            'Waspadai gempa susulan; menjauh dari bangunan yang retak atau miring.',
            'Ikuti kabar resmi dari BMKG dan BPBD. Jangan menyebarkan kabar yang belum jelas.',
        ],
    },
    {
        key: 'luar',
        label: 'Di luar dan kendaraan',
        icon: 'pin',
        tone: 'bg-brand',
        steps: [
            'Di luar ruangan, menjauh dari bangunan, tiang, dan kabel listrik. Cari tempat terbuka.',
            'Di kendaraan, hentikan di tempat aman jauh dari jembatan dan pepohonan, lalu tetap di dalam.',
            'Jangan memakai lift. Jangan berlari keluar saat bangunan masih berguncang.',
        ],
    },
    {
        key: 'pantai',
        label: 'Di dekat pantai',
        icon: 'wave',
        tone: 'bg-tsunami',
        steps: [
            'Bila guncangan kuat atau terasa lama, segera menjauh dari pantai ke tempat yang tinggi tanpa menunggu peringatan.',
            'Ikuti jalur evakuasi tsunami yang ditetapkan setempat.',
            'Tetap di tempat tinggi sampai petugas menyatakan aman; gelombang bisa datang berulang.',
        ],
    },
    {
        key: 'siap',
        label: 'Siapkan sebelumnya',
        icon: 'bag',
        tone: 'bg-[#ff7a2f]',
        steps: [
            'Siapkan tas siaga dan simpan di tempat yang mudah dijangkau (daftarnya ada di bawah).',
            'Kenali jalur evakuasi dan lokasi posko pengungsian terdekat di peta.',
            'Sepakati titik kumpul keluarga dan simpan nomor darurat di ponsel.',
        ],
    },
];

const active = ref('saat');
const current = computed(() => TABS.find((t) => t.key === active.value));

// Panah kiri dan kanan memindahkan tab (pola tab yang lazim di papan ketik).
function onKey(e) {
    const i = TABS.findIndex((t) => t.key === active.value);
    const next = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? TABS.length - 1 : null;
    if (next === null) return;

    e.preventDefault();
    active.value = TABS[(next + TABS.length) % TABS.length].key;
    document.getElementById(`tab-${active.value}`)?.focus();
}

// Semua dari kanal YouTube "Info BMKG"; judul sesuai yang tertera di kanalnya.
const VIDEOS = [
    { id: '1nXkJq5OZl0', title: 'Apa sih gempabumi itu?', note: 'Penjelasan dasar penyebab gempa.' },
    { id: 'mEL6vm1bujc', title: 'Mitigasi menghadapi gempabumi', note: 'Persiapan dan tindakan saat gempa.' },
    { id: 'dbfvYnpoDFM', title: 'Evakuasi saat terjadi tsunami', note: 'Siaga Tsunami, episode 3.' },
    { id: 'ER92JACcoec', title: 'Gerakan sadar tas siaga bencana', note: 'Apa saja yang perlu ada di tas siaga.' },
];

const CONTACTS = [
    { number: '112', label: 'Layanan darurat terpadu', tone: 'from-[#ff6a4d] to-[#ff9a6a]' },
    { number: '115', label: 'Basarnas: pencarian dan pertolongan', tone: 'from-[#1f56e8] to-[#5b8dff]' },
    { number: '117', label: 'BNPB: penanggulangan bencana', tone: 'from-[#0f9b8e] to-[#34d399]' },
];
</script>

<template>
    <Head title="Panduan saat gempa" />

    <PublicLayout>
        <PageHero title="Cara selamat saat gempa" icon="shield" tone="violet" subtitle="Langkah sederhana yang bisa dihafal semua orang, dari anak-anak sampai orang tua, dengan gambar dan video." />

        <div class="mx-auto -mt-16 max-w-6xl space-y-14 px-4 pb-14 md:px-6">
            <!-- Nomor darurat -->
            <ul class="relative z-10 grid gap-3 sm:grid-cols-3" aria-label="Nomor darurat">
                <li v-for="(c, i) in CONTACTS" :key="c.number" v-reveal="i * 80">
                    <a :href="`tel:${c.number}`" class="lift flex items-center gap-4 rounded-3xl bg-white p-4 shadow-md ring-1 ring-line">
                        <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br text-white shadow-md" :class="c.tone"><Icon name="phone" :size="26" /></span>
                        <span>
                            <span class="mag block text-4xl">{{ c.number }}</span>
                            <span class="mt-0.5 block text-sm font-semibold leading-snug text-muted">{{ c.label }}</span>
                        </span>
                    </a>
                </li>
            </ul>

            <!-- Tab topik -->
            <section aria-labelledby="h-topik" v-reveal>
                <h2 id="h-topik" class="text-2xl font-extrabold md:text-3xl">Pilih keadaanmu</h2>

                <div class="mt-4 -mx-4 overflow-x-auto px-4 pb-2 md:mx-0 md:px-0" role="tablist" aria-label="Topik panduan" @keydown="onKey">
                    <div class="flex w-max gap-2 md:w-auto md:flex-wrap">
                        <button
                            v-for="t in TABS"
                            :id="`tab-${t.key}`"
                            :key="t.key"
                            type="button"
                            role="tab"
                            :aria-selected="active === t.key"
                            :aria-controls="`panel-${t.key}`"
                            :tabindex="active === t.key ? 0 : -1"
                            class="flex shrink-0 items-center gap-2 rounded-full px-5 py-3 text-base font-extrabold transition-all"
                            :class="active === t.key ? 'bg-ink text-white shadow-md' : 'bg-white text-ink ring-1 ring-line hover:bg-brand-soft'"
                            @click="active = t.key"
                        >
                            <span class="grid size-7 place-items-center rounded-full text-white" :class="t.tone"><Icon :name="t.icon" :size="16" /></span>{{ t.label }}
                        </button>
                    </div>
                </div>

                <div :id="`panel-${active}`" role="tabpanel" :aria-labelledby="`tab-${active}`" class="mt-5" tabindex="0">
                    <ShakeSteps v-if="active === 'saat'" />

                    <ol v-else :key="active" class="grid gap-3 md:grid-cols-2">
                        <li v-for="(step, i) in current.steps" :key="step" v-reveal="i * 70" class="flex gap-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-line">
                            <span class="mag grid size-12 shrink-0 place-items-center rounded-2xl text-2xl text-white" :class="current.tone">{{ i + 1 }}</span>
                            <span class="self-center text-lg leading-snug">{{ step }}</span>
                        </li>
                    </ol>
                </div>
            </section>

            <section aria-labelledby="h-tas" v-reveal>
                <h2 id="h-tas" class="text-2xl font-extrabold md:text-3xl">Siapkan tas siaga</h2>
                <div class="mt-4"><PrepChecklist /></div>
            </section>

            <section aria-labelledby="h-gelombang" class="grid items-center gap-6 md:grid-cols-[1.2fr_1fr] md:gap-10">
                <div v-reveal><WaveExplainer /></div>
                <div v-reveal="120">
                    <h2 id="h-gelombang" class="text-2xl font-extrabold leading-tight md:text-3xl">Kenapa guncangan kuat datang belakangan?</h2>
                    <p class="mt-3 text-base leading-relaxed text-muted">
                        Gempa berawal di dalam bumi, di titik yang disebut pusat gempa. Dari sana dua jenis gelombang merambat: gelombang P yang lebih cepat dan terasa sebagai
                        getaran pertama, lalu gelombang S yang menyusul dan mengguncang paling kuat.
                    </p>
                    <p class="mt-3 text-base leading-relaxed text-muted">
                        Jeda itu mungkin hanya beberapa detik. Begitu getaran pertama terasa, jangan menunggu: langsung merunduk, berlindung, dan bertahan.
                    </p>
                </div>
            </section>

            <section aria-labelledby="h-video">
                <h2 id="h-video" class="text-2xl font-extrabold md:text-3xl" v-reveal>Video dari BMKG</h2>
                <p class="mt-1.5 max-w-xl text-base text-muted" v-reveal>Dari kanal YouTube Info BMKG. Ketuk gambar untuk memutar.</p>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div v-for="(v, i) in VIDEOS" :key="v.id" v-reveal="i * 80"><VideoCard :id="v.id" :title="v.title" :note="v.note" /></div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3" v-reveal>
                <Link href="/peta" class="inline-flex items-center gap-2 rounded-full bg-brand px-6 py-3 text-base font-extrabold text-white shadow-md transition-transform hover:scale-[1.03] active:scale-95"><Icon name="map" :size="20" />Cari posko di peta</Link>
                <Link href="/berita" class="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-base font-extrabold shadow-sm ring-1 ring-line transition-colors hover:bg-brand-soft"><Icon name="news" :size="20" />Berita terkini</Link>
            </div>
        </div>
    </PublicLayout>
</template>
