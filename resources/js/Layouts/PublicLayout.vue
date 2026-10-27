<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandMark from '../components/BrandMark.vue';
import Icon from '../components/Icon.vue';
import { useTextSize } from '../composables/useTextSize';

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const user = computed(() => page.props.auth.user);
const { big, toggle } = useTextSize();

const nav = [
    { href: '/', label: 'Pantauan', icon: 'map', match: (u) => u === '/' || u.startsWith('/peta') },
    { href: '/cuaca', label: 'Cuaca', icon: 'pulse', match: (u) => u.startsWith('/cuaca') },
    { href: '/tentang', label: 'Sumber data', icon: 'info', match: (u) => u.startsWith('/tentang') },
    { href: '/berita', label: 'Berita', icon: 'news', match: (u) => u.startsWith('/berita') },
    { href: '/panduan', label: 'Panduan', icon: 'shield', match: (u) => u.startsWith('/panduan') },
];

// Tujuan "Masuk" sesuai peran, supaya pengguna yang sudah login langsung ke tempat kerjanya.
const workHref = computed(() => {
    const u = user.value;
    if (!u) return '/masuk';
    if (u.can['dispatch.create'] || u.can['demand.view-all']) return '/logistik';
    if (u.can['field-report.create']) return '/lapangan';

    return '/akun';
});

const accountLabel = computed(() => (user.value ? 'Ruang kerja' : 'Masuk'));
</script>

<template>
    <div class="public-shell flex min-h-dvh flex-col">
        <a href="#isi" class="absolute left-3 top-3 z-[1000] -translate-y-20 rounded-lg bg-white px-3 py-2 text-sm font-bold text-trench shadow-lg focus:translate-y-0">Lewati ke isi halaman</a>

        <header class="sticky top-0 z-[600] border-b border-line/80 bg-white/85 backdrop-blur-md">
            <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-2.5 md:gap-6 md:px-6">
                <Link href="/" class="flex items-center gap-2.5" aria-label="IGI, Info Gempa Indonesia: ke beranda">
                    <BrandMark :size="38" />
                    <span class="leading-none">
                        <span class="block text-xl font-extrabold tracking-tight text-ink">IGI</span>
                        <span class="hidden text-[0.7rem] font-semibold text-muted sm:block">Info Gempa Indonesia</span>
                    </span>
                </Link>

                <nav aria-label="Menu utama" class="ml-2 hidden items-center gap-1 md:flex">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold transition-colors"
                        :class="item.match(path) ? 'bg-brand text-white shadow-sm' : 'text-muted hover:bg-brand-soft hover:text-brand'"
                        :aria-current="item.match(path) ? 'page' : undefined"
                    >
                        <Icon :name="item.icon" :size="18" />{{ item.label }}
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full border border-line bg-white px-3 py-2 text-sm font-bold text-ink transition-colors hover:bg-brand-soft"
                        :class="big ? 'border-brand bg-brand-soft text-brand' : ''"
                        :aria-pressed="big"
                        title="Perbesar atau normalkan ukuran teks"
                        @click="toggle"
                    >
                        <Icon name="text" :size="18" /><span class="hidden sm:inline">{{ big ? 'Teks besar' : 'Teks normal' }}</span><span class="sr-only sm:hidden">Ukuran teks</span>
                    </button>
                    <Link :href="workHref" class="hidden items-center gap-2 rounded-full bg-ink px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-deep md:flex">
                        <Icon :name="user ? 'user' : 'login'" :size="18" />{{ accountLabel }}
                    </Link>
                </div>
            </div>
        </header>

        <main id="isi" class="flex-1 scroll-mt-16 pb-24 md:pb-0"><slot /></main>

        <!-- Bilah bawah di ponsel: lima tujuan utama dalam jangkauan ibu jari. -->
        <nav class="fixed inset-x-0 bottom-0 z-[700] border-t border-line bg-white/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_-12px_rgb(15_27_61/0.25)] backdrop-blur-md md:hidden" aria-label="Menu bawah">
            <ul class="mx-auto grid max-w-md grid-cols-6">
                <li v-for="item in nav" :key="item.href">
                    <Link :href="item.href" class="flex flex-col items-center gap-0.5 px-1 pb-1.5 pt-2 text-[0.7rem] font-bold" :class="item.match(path) ? 'text-brand' : 'text-muted'" :aria-current="item.match(path) ? 'page' : undefined">
                        <span class="grid h-8 w-14 place-items-center rounded-full transition-colors" :class="item.match(path) ? 'bg-brand-soft' : ''"><Icon :name="item.icon" :size="22" /></span>
                        {{ item.label }}
                    </Link>
                </li>
                <li>
                    <Link :href="workHref" class="flex flex-col items-center gap-0.5 px-1 pb-1.5 pt-2 text-[0.7rem] font-bold" :class="['/masuk', '/akun', '/logistik', '/lapangan'].includes(path) ? 'text-brand' : 'text-muted'">
                        <span class="grid h-8 w-14 place-items-center rounded-full"><Icon :name="user ? 'user' : 'login'" :size="22" /></span>
                        {{ accountLabel }}
                    </Link>
                </li>
            </ul>
        </nav>

        <footer class="bg-trench text-white">
            <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 pb-28 md:grid-cols-[1.3fr_1fr] md:px-6 md:pb-10">
                <div>
                    <div class="flex items-center gap-3">
                        <BrandMark :size="34" />
                        <p class="text-xl font-extrabold tracking-tight">IGI <span class="font-semibold text-white/60">Info Gempa Indonesia</span></p>
                    </div>
                    <p class="mt-4 max-w-xl text-sm leading-relaxed text-white/75">
                        Data gempa dari BMKG dan USGS, diperbarui otomatis. IGI bukan pengganti peringatan resmi: untuk arahan evakuasi, ikuti BMKG, BPBD, dan BNPB.
                        Kutipan berita milik BMKG dan BNPB; video milik kanal Info BMKG; garis pantai dari Natural Earth.
                    </p>
                    <nav aria-label="Tautan kaki" class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold">
                        <Link href="/tentang" class="underline underline-offset-4 hover:text-trace">Tentang data dan sumbernya</Link>
                        <a href="/feed.xml" class="underline underline-offset-4 hover:text-trace">Umpan Atom gempa</a>
                        <Link href="/daftar" class="underline underline-offset-4 hover:text-trace">Daftar sebagai petugas</Link>
                    </nav>
                </div>
                <div>
                    <p class="text-sm font-bold text-white/70">Nomor darurat</p>
                    <dl class="mt-3 grid grid-cols-3 gap-3">
                        <div v-for="[label, no] in [['Darurat', '112'], ['Basarnas', '115'], ['BNPB', '117']]" :key="no" class="rounded-2xl bg-white/10 px-3 py-2">
                            <dt class="text-xs text-white/65">{{ label }}</dt>
                            <dd><a :href="`tel:${no}`" class="mag flex min-h-11 items-center text-3xl hover:text-trace">{{ no }}</a></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.public-shell { color: #233444; font-family: 'Figtree Variable', 'Plus Jakarta Sans Variable', sans-serif; }
header { background: #ffffff; backdrop-filter: none; border-top: 0; border-bottom: 1px solid #dce2e7; }
header > div { max-width: 1440px; padding: 12px 40px; }
header nav { margin-left: auto; }
header nav a { border-radius: 0; padding: 9px 14px; box-shadow: none; background: transparent; color: #657481; font-weight: 600; }
header nav a[aria-current='page'] { background: transparent; color: #233444; box-shadow: inset 0 -2px #215b84; }
header nav a:hover { background: #edf3f8; }
header nav :deep(svg) { display: none; }
header .ml-auto { margin-left: 24px; }
header button { border-radius: 0; background: transparent; border-color: #d3dde5; font-size: .7rem; }
header .ml-auto > a { background: #17334c; border-radius: 0; font-size: .72rem; }
footer { background: #17334c; }
footer > div { max-width: 1440px; padding-inline: 40px; }
footer dl > div { border-radius: 0; background: transparent; border-left: 1px solid #496273; }
footer .mag { font-weight: 600; }
.public-shell > nav { background: #ffffff; backdrop-filter: none; box-shadow: none; border-color: #d3dde5; }
.public-shell > nav a { color: #657481; }
.public-shell > nav a[aria-current='page'] { color: #215b84; }
.public-shell > nav a > span { border-radius: 0; }
.public-shell > nav a[aria-current='page'] > span { background: #edf3f8; }
@media(max-width: 1100px) { header > div { padding-inline: 26px; } header nav a { padding-inline: 10px; } header .ml-auto { margin-left: auto; } }
@media(max-width: 767px) { header > div { padding: 9px 16px; } footer > div { padding-inline: 16px; } header .ml-auto { margin-left: auto; } }
</style>
