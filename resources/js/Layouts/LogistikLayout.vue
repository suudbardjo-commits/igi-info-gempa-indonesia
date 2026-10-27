<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import BrandMark from '../components/BrandMark.vue';
import ToastHost from '../components/ToastHost.vue';
import { useToast } from '../composables/useToast';
import { echo } from '../echo';
import { formatMagnitude } from '../lib/quake';
import { logout } from '../lib/session';

defineProps({ title: { type: String, required: true } });

const page = usePage();
const { toast } = useToast();

const user = computed(() => page.props.auth.user);
const roleLabel = computed(
    () =>
        ({
            'super-admin': 'Super admin',
            'bpbd-operator': 'Operator BPBD',
            'warehouse-manager': 'Pengelola gudang',
        })[user.value.roles[0]] ?? user.value.roles[0],
);

const nav = computed(() =>
    [
        { href: '/logistik', label: 'Permintaan', match: (u) => u === '/logistik' },
        { href: '/logistik/pengiriman', label: 'Pengiriman', match: (u) => u.startsWith('/logistik/pengiriman') },
        { href: '/logistik/stok', label: 'Stok gudang', match: (u) => u.startsWith('/logistik/stok') },
        { href: '/logistik/laporan', label: 'Laporan lapangan', match: (u) => u.startsWith('/logistik/laporan') },
        user.value.can['user.review'] && { href: '/logistik/petugas', label: 'Pendaftaran', badge: page.props.pendingRegistrations, match: (u) => u.startsWith('/logistik/petugas') },
        user.value.can['announcement.manage'] && { href: '/logistik/pengumuman', label: 'Pengumuman', match: (u) => u.startsWith('/logistik/pengumuman') },
        user.value.can['audit.view'] && { href: '/logistik/audit', label: 'Jejak audit', match: (u) => u.startsWith('/logistik/audit') },
        // Di layar lebar tautan Akun ada di bagian bawah rel; di ponsel masuk ke deretan menu.
        { href: '/akun', label: 'Akun', match: (u) => u === '/akun', mobileOnly: true },
    ].filter(Boolean),
);

const path = computed(() => page.url.split('?')[0]);

// Peringatan dampak gempa langsung ke konsol: berapa posko terancam dan mana yang terdekat.
function onLogisticsAlert(e) {
    const count = e.camps.length;
    const nearest = e.camps[0];

    toast(
        `Gempa M ${formatMagnitude(e.disaster.magnitude)} di ${e.disaster.location}: ${count} posko dalam radius dampak, terdekat ${nearest.name} (${nearest.distance_km.toLocaleString('id-ID', { maximumFractionDigits: 1 })} km).`,
        'alert',
    );
}

// Posko melaporkan kondisi darurat dari lapangan.
function onCondition(e) {
    toast(`Kondisi darurat di ${e.camp.name}: ${e.note}`, 'alert');
}

onMounted(() => echo.private('logistics').listen('.logistics.alert', onLogisticsAlert).listen('.camp.condition', onCondition));
onBeforeUnmount(() => echo.leave('logistics'));
</script>

<template>
    <div class="min-h-dvh md:grid md:grid-cols-[14.5rem_minmax(0,1fr)]">
        <aside class="flex flex-col gap-3 bg-gradient-to-b from-[#0a1630] to-[#14307f] px-4 py-3 text-white md:sticky md:top-0 md:h-dvh md:gap-8 md:px-5 md:py-6">
            <div class="flex items-center justify-between gap-3 md:block">
                <a href="/" class="flex items-center gap-2.5" aria-label="IGI: ke beranda situs publik">
                    <BrandMark :size="30" />
                    <span class="leading-none">
                        <span class="block text-2xl font-extrabold tracking-tight">IGI</span>
                        <span class="block text-[0.7rem] text-white/60">Logistik bantuan</span>
                    </span>
                </a>
                <button type="button" class="rounded px-2 py-1 text-sm text-white/70 hover:text-white md:hidden" @click="logout">Keluar</button>
            </div>

            <nav aria-label="Menu konsol" class="-mx-1 flex gap-1 overflow-x-auto md:flex-col md:overflow-visible">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="shrink-0 rounded-full px-4 py-2.5 text-sm font-bold transition-colors"
                    :class="[item.match(path) ? 'bg-white text-brand shadow-sm' : 'text-white/80 hover:bg-white/12 hover:text-white', item.mobileOnly ? 'md:hidden' : '']"
                    :aria-current="item.match(path) ? 'page' : undefined"
                >
                    {{ item.label }}
                    <span v-if="item.badge" class="ml-1.5 rounded-full bg-quake-3 px-1.5 py-0.5 text-xs font-bold text-white" :aria-label="`${item.badge} menunggu`">{{ item.badge }}</span>
                </Link>
            </nav>

            <div class="mt-auto hidden space-y-3 text-sm md:block">
                <Link href="/akun" class="block text-white/70 hover:text-white">Akun dan notifikasi Telegram</Link>
                <a href="/" class="block text-white/70 hover:text-white">Lihat situs publik</a>
                <div class="border-t border-white/15 pt-3">
                    <p class="font-medium">{{ user.name }}</p>
                    <p class="text-xs text-white/60">{{ roleLabel }}</p>
                    <button type="button" class="mt-2 text-white/70 underline underline-offset-2 hover:text-white" @click="logout">Keluar</button>
                </div>
            </div>
        </aside>

        <main class="min-w-0 px-4 py-5 md:px-8 md:py-7">
            <h1 class="text-3xl font-extrabold leading-tight tracking-tight">{{ title }}</h1>
            <slot />
        </main>

        <ToastHost />
    </div>
</template>
