<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import BrandMark from '../components/BrandMark.vue';
import HeroWaves from '../components/HeroWaves.vue';
import Icon from '../components/Icon.vue';

const form = useForm({ email: '', password: '' });
const showPassword = ref(false);

function submit() {
    form.post('/masuk', { onFinish: () => form.reset('password') });
}

const POINTS = [
    ['box', 'Kelola stok dan pengiriman bantuan'],
    ['pin', 'Laporkan kondisi posko dari lapangan'],
    ['bell', 'Terima peringatan gempa untuk posko Anda'],
];

const field = 'mt-1.5 w-full rounded-2xl border-2 border-line bg-white px-4 py-3.5 text-base outline-none transition-colors focus:border-brand';
</script>

<template>
    <Head title="Masuk" />

    <main class="grid min-h-dvh md:grid-cols-[1.05fr_1fr]">
        <!-- Panel merek (hanya di layar lebar) -->
        <aside class="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-[#0a1630] via-[#10307f] to-[#1f56e8] p-10 text-white md:flex">
            <HeroWaves />
            <a href="/" class="relative flex items-center gap-3" aria-label="IGI, Info Gempa Indonesia: ke beranda">
                <BrandMark :size="46" />
                <span class="text-2xl font-extrabold tracking-tight">IGI <span class="font-semibold text-white/70">Info Gempa Indonesia</span></span>
            </a>
            <div class="relative pb-24">
                <h2 class="text-5xl font-extrabold leading-[1.05] tracking-tight">Bantu posko,<br />tepat waktu.</h2>
                <ul class="mt-8 space-y-4">
                    <li v-for="[icon, text] in POINTS" :key="text" class="flex items-center gap-3 text-lg font-semibold">
                        <span class="grid size-11 place-items-center rounded-2xl bg-white/15 backdrop-blur-sm"><Icon :name="icon" :size="22" /></span>{{ text }}
                    </li>
                </ul>
            </div>
        </aside>

        <section class="flex items-center justify-center bg-mist px-4 py-10">
            <div class="w-full max-w-md">
                <a href="/" class="mb-6 flex items-center gap-2.5 md:hidden" aria-label="IGI, Info Gempa Indonesia: ke beranda">
                    <BrandMark :size="42" />
                    <span class="text-2xl font-extrabold tracking-tight">IGI</span>
                </a>

                <div class="rounded-[2rem] bg-white p-6 shadow-xl ring-1 ring-line md:p-8">
                    <h1 class="text-3xl font-extrabold tracking-tight">Masuk petugas</h1>
                    <p class="mt-2 text-base text-muted">Untuk pengelola gudang, petugas koordinasi, koordinator posko, dan relawan.</p>

                    <form class="mt-6 space-y-5" @submit.prevent="submit">
                        <div>
                            <label for="email" class="block text-base font-bold">Email</label>
                            <input id="email" v-model="form.email" type="email" autocomplete="username" inputmode="email" required autofocus :class="field" :aria-invalid="!!form.errors.email" aria-describedby="email-error" />
                        </div>

                        <div>
                            <label for="password" class="block text-base font-bold">Kata sandi</label>
                            <div class="relative">
                                <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required :class="field" class="pr-24" />
                                <button type="button" class="absolute right-2 top-1/2 mt-0.5 -translate-y-1/2 rounded-full px-3 py-1.5 text-sm font-bold text-brand hover:bg-brand-soft" :aria-pressed="showPassword" @click="showPassword = !showPassword">{{ showPassword ? 'Sembunyikan' : 'Tampilkan' }}</button>
                            </div>
                        </div>

                        <p v-if="form.errors.email" id="email-error" class="rounded-2xl border-l-4 border-quake-3 bg-quake-3/5 px-4 py-3 text-base" role="alert">{{ form.errors.email }}</p>

                        <button type="submit" class="sheen relative w-full overflow-hidden rounded-full bg-brand px-4 py-4 text-lg font-extrabold text-white shadow-md transition-transform hover:scale-[1.01] active:scale-[0.99] disabled:opacity-60" :disabled="form.processing">
                            {{ form.processing ? 'Memeriksa…' : 'Masuk' }}
                        </button>
                    </form>

                    <p class="mt-6 text-base text-muted">Belum punya akun? <a href="/daftar" class="font-bold text-brand underline underline-offset-4">Daftar sebagai petugas</a></p>
                </div>

                <p class="mt-5 text-center text-base"><a href="/" class="font-bold text-muted underline underline-offset-4 hover:text-ink">Kembali ke beranda</a></p>
            </div>
        </section>
    </main>
</template>
