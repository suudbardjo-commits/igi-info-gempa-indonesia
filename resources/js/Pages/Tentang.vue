<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHero from '../components/PageHero.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';
import { formatAgo, formatWib } from '../lib/quake';

defineProps({ sources: { type: Array, required: true } });

const CREDITS = [
    { name: 'Gempa resmi', text: 'BMKG (data.bmkg.go.id) adalah sumber utama dan diteruskan apa adanya. USGS melengkapi gempa M 4+ yang belum ada di BMKG dan selalu ditandai sumbernya; bila kejadiannya sama, yang BMKG yang ditampilkan. Di Pusat Komando, gempa dari EMSC ditandai "menunggu validasi BMKG" sampai ada pasangan resmi.' },
    { name: 'Prakiraan cuaca', text: 'Titik yang dikenali sebagai desa atau kelurahan memakai prakiraan resmi BMKG. Bila tidak dikenali atau BMKG gagal, dipakai estimasi model Open-Meteo dan diberi label sendiri.' },
    { name: 'Peringatan cuaca dan berita', text: 'BMKG dan BNPB. Hanya judul, kutipan satu kalimat, dan tautan yang disimpan; hak cipta ada pada penerbitnya.' },
    { name: 'Perkiraan dan laporan warga', text: 'Estimasi guncangan (MMI) dihitung dengan rumus kasar, bukan hasil pengukuran. Laporan "dirasakan" dari warga tidak diverifikasi.' },
    { name: 'Video', text: 'Kanal YouTube Info BMKG. Video baru dimuat dari YouTube setelah Anda menekan tombol putar.' },
    { name: 'Garis pantai', text: 'Natural Earth (domain publik).' },
];

const LIMITS = [
    'Data diambil otomatis dan bisa tertunda atau direvisi sumbernya setelah dipublikasikan. Magnitudo dan lokasi awal sering berubah.',
    'IGI tidak mengeluarkan peringatan dini dan tidak menggantikannya. Untuk arahan evakuasi, ikuti BMKG, BPBD, dan petugas di lapangan.',
    'Radius dampak adalah perkiraan sederhana menurut magnitudo, bukan model guncangan. Jarak ke posko dihitung garis lurus, bukan rute jalan.',
    'Jumlah pengungsi di posko berasal dari laporan petugas dan relawan di lapangan. Laporan yang dicatat saat tanpa sinyal baru muncul setelah terkirim.',
];
</script>

<template>
    <Head title="Tentang data" />

    <PublicLayout>
        <PageHero title="Dari mana data ini berasal" icon="info" tone="teal" subtitle="Tiap sumber, seberapa sering diambil, dan kapan terakhir berhasil. Bila sebuah sumber tertunda, itu terlihat di sini." />

        <div class="mx-auto -mt-16 max-w-4xl space-y-12 px-4 pb-14 md:px-6">
            <section aria-labelledby="h-status">
                <h2 id="h-status" class="relative z-10 text-2xl font-extrabold text-white md:text-3xl">Status pengambilan data</h2>
                <ul class="mt-4 divide-y divide-line overflow-hidden rounded-3xl bg-white shadow-md ring-1 ring-line">
                    <li v-for="s in sources" :key="s.key" class="grid gap-x-6 gap-y-1 px-4 py-4 sm:grid-cols-[1fr_auto] md:px-5">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ s.name }}</p>
                            <p class="text-sm text-muted">{{ s.what }}, {{ s.every }}.</p>
                        </div>
                        <p class="text-sm sm:text-right" :class="s.stale ? 'font-semibold text-quake-4' : 'text-camp font-semibold'">
                            <template v-if="s.last_ok">
                                {{ s.stale ? 'Tertunda' : 'Normal' }}
                                <span class="block font-normal text-muted" :title="formatWib(s.last_ok)">terakhir berhasil {{ formatAgo(s.last_ok) }}</span>
                            </template>
                            <template v-else>
                                Belum ada catatan
                                <span class="block font-normal text-muted">menunggu pengambilan pertama</span>
                            </template>
                        </p>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="h-sumber" class="grid gap-x-10 gap-y-8 md:grid-cols-2">
                <div class="md:col-span-2"><h2 id="h-sumber" class="text-2xl font-extrabold md:text-3xl">Sumber dan hak cipta</h2></div>
                <dl class="space-y-4 text-sm leading-relaxed md:col-span-2 md:grid md:grid-cols-2 md:gap-x-10 md:space-y-0 md:gap-y-5">
                    <div v-for="c in CREDITS" :key="c.name"><dt class="font-semibold">{{ c.name }}</dt><dd class="mt-0.5 text-muted">{{ c.text }}</dd></div>
                </dl>
            </section>

            <section aria-labelledby="h-batas">
                <h2 id="h-batas" class="text-2xl font-extrabold md:text-3xl">Batas yang perlu Anda tahu</h2>
                <ul class="mt-4 list-disc space-y-2.5 pl-5 text-sm leading-relaxed">
                    <li v-for="l in LIMITS" :key="l">{{ l }}</li>
                </ul>
            </section>

            <section aria-labelledby="h-pakai">
                <h2 id="h-pakai" class="text-2xl font-extrabold md:text-3xl">Pakai data IGI</h2>
                <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted">
                    Gempa terbaru tersedia sebagai <a href="/feed.xml" class="font-semibold text-ink underline underline-offset-2">umpan Atom</a> untuk pembaca RSS, dan sebagai GeoJSON di
                    <a href="/api/v1/disasters" class="font-semibold text-ink underline underline-offset-2">/api/v1/disasters</a> (terbuka, dibatasi 60 permintaan per menit). Sebutkan IGI, BMKG, dan USGS sebagai sumber.
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <Link href="/peta" class="rounded-md bg-trench px-4 py-2.5 text-sm font-bold text-white hover:bg-deep">Buka peta gempa</Link>
                    <Link href="/daftar" class="rounded-md border border-line px-4 py-2.5 text-sm font-bold hover:bg-white">Daftar sebagai petugas</Link>
                </div>
            </section>
        </div>
    </PublicLayout>
</template>
