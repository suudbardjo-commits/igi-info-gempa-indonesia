<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandMark from '../components/BrandMark.vue';
import { useTextSize } from '../composables/useTextSize';

// Pita judul biru bergaya aplikasi bencana BMKG, sama dengan aplikasi Android. Dipakai di beranda dan halaman Cuaca.
const props = defineProps({ active: { type: String, default: 'peta' } });
const page = usePage();
const { big, toggle } = useTextSize();

const nav = [
    { key: 'peta', href: '/', label: 'Peta' },
    { key: 'cuaca', href: '/cuaca', label: 'Cuaca' },
    { key: 'komando', href: '/komando', label: 'Komando' },
    { key: 'berita', href: '/berita', label: 'Berita' },
    { key: 'panduan', href: '/panduan', label: 'Panduan' },
    { key: 'tentang', href: '/tentang', label: 'Tentang' },
];
const workHref = computed(() => {
    const u = page.props.auth?.user;
    if (!u) return '/masuk';
    if (u.can?.['dispatch.create'] || u.can?.['demand.view-all']) return '/logistik';

    return u.can?.['field-report.create'] ? '/lapangan' : '/akun';
});
const workLabel = computed(() => (page.props.auth?.user ? 'Ruang kerja' : 'Petugas'));
</script>

<template>
    <header class="band">
        <Link href="/" class="brand" aria-label="IGI, Info Gempa Indonesia: ke beranda">
            <span class="tile"><BrandMark :size="30" /></span>
            <span><b>IGI</b><small>Info Gempa Indonesia</small></span>
        </Link>
        <nav aria-label="Navigasi utama">
            <Link v-for="n in nav" :key="n.key" :href="n.href" :aria-current="n.key === props.active ? 'page' : undefined">{{ n.label }}</Link>
        </nav>
        <div class="tools">
            <button type="button" class="aa" :aria-pressed="big" aria-label="Perbesar ukuran teks" @click="toggle">Aa</button>
            <Link :href="workHref" class="work">{{ workLabel }}</Link>
        </div>
    </header>
</template>

<style scoped>
.band { display: flex; align-items: center; gap: 20px; height: 56px; padding: 0 16px; background: #0b5ca5; color: #fff; font-family: 'Barlow', Arial, sans-serif; position: relative; z-index: 1300; }
.brand { display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; flex: none; }
.tile { display: grid; place-items: center; width: 40px; height: 40px; background: #fff; border-radius: 8px; }
.brand b { display: block; font-family: 'Barlow Condensed', sans-serif; font-weight: 700; font-size: 26px; line-height: 1; }
.brand small { display: block; font-size: 12px; color: #d6e6f5; }
nav { display: flex; gap: 4px; margin-left: auto; }
nav a { display: inline-flex; align-items: center; min-height: 44px; padding: 0 12px; color: #d6e6f5; text-decoration: none; font-size: 15px; border-bottom: 3px solid transparent; }
nav a:hover { color: #fff; }
nav a[aria-current] { color: #fff; font-weight: 700; border-bottom-color: #fff; }
.tools { display: flex; align-items: center; gap: 8px; }
.aa, .work { min-height: 40px; padding: 0 12px; display: inline-flex; align-items: center; border: 1px solid #8db9e3; border-radius: 8px; background: transparent; color: #fff; font: inherit; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
.aa:hover, .work:hover { background: #0a4f8f; }
.aa[aria-pressed='true'] { background: #fff; color: #0b5ca5; }
.band :is(a, button):focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
@media (max-width: 900px) { .band { flex-wrap: wrap; height: auto; min-height: 56px; gap: 0 12px; padding-top: 8px; } .tools { margin-left: auto; } nav { order: 3; width: 100%; margin: 0; gap: 0; overflow-x: auto; scrollbar-width: none; } nav a { min-height: 40px; white-space: nowrap; } }
@media (max-width: 767px) { .brand small { display: none; } }
</style>
