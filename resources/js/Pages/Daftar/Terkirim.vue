<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Icon from '../../components/Icon.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';
</script>

<template>
    <Head title="Pendaftaran terkirim" />

    <PublicLayout>
        <div class="mx-auto max-w-xl px-4 py-14 md:px-6 md:py-20">
            <span class="mx-auto grid size-20 place-items-center rounded-full bg-gradient-to-br from-[#0f9b8e] to-[#34d399] text-white shadow-lg"><Icon name="check" :size="40" :stroke="3" /></span>
            <h1 class="mt-5 text-center text-4xl font-extrabold tracking-tight">Pendaftaran terkirim</h1>
            <p class="mt-4 text-center text-lg leading-relaxed">Operator BPBD akan meninjau pendaftaran Anda. Akun belum bisa dipakai masuk sampai disetujui.</p>
            <ul class="mt-6 list-disc space-y-2 rounded-3xl bg-white p-6 pl-9 text-base leading-relaxed text-muted shadow-sm ring-1 ring-line">
                <li>Kami tidak mengirim email otomatis. Hubungi operator BPBD di instansi Anda agar pendaftaran segera ditinjau.</li>
                <li>Setelah disetujui, masuk dengan email dan kata sandi yang tadi Anda buat.</li>
                <li>Bila email ini sudah pernah terdaftar, tidak ada akun baru yang dibuat. Gunakan Masuk, atau minta operator mengatur ulang akun Anda.</li>
            </ul>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <Link href="/masuk" class="rounded-full bg-brand px-6 py-3 text-base font-extrabold text-white shadow-md transition-transform hover:scale-[1.03]">Ke halaman masuk</Link>
                <Link href="/" class="rounded-full bg-white px-6 py-3 text-base font-extrabold shadow-sm ring-1 ring-line hover:bg-brand-soft">Kembali ke beranda</Link>
            </div>
        </div>
    </PublicLayout>
</template>
