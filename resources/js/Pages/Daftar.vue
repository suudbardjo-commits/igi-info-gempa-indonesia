<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHero from '../components/PageHero.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';

defineProps({ roles: { type: Array, required: true } });

const form = useForm({
    name: '',
    email: '',
    phone_number: '',
    password: '',
    password_confirmation: '',
    requested_role: 'field-volunteer',
    organization: '',
    request_note: '',
    website: '',
});

const ROLE_HELP = {
    'field-volunteer': 'Melaporkan jumlah pengungsi dan kondisi posko dari lapangan.',
    'camp-coordinator': 'Mengampu satu posko: melapor, meminta bantuan, dan menerima kiriman.',
    'warehouse-manager': 'Mengelola stok dan pengiriman bantuan dari satu gudang.',
};

function submit() {
    form.post('/daftar', { onFinish: () => form.reset('password', 'password_confirmation') });
}

const field = 'mt-1 w-full rounded-md border border-line bg-surface px-3 py-2 text-sm';
</script>

<template>
    <Head title="Daftar petugas" />

    <PublicLayout>
        <PageHero title="Daftar sebagai petugas" icon="user" subtitle="Untuk relawan, koordinator posko, dan pengelola gudang. Akun baru belum bisa dipakai sampai operator BPBD menyetujuinya dan menetapkan posko atau gudang Anda." />

        <div class="mx-auto -mt-16 max-w-2xl px-4 pb-14 md:px-6">
          <div class="relative z-10 rounded-[2rem] bg-white p-5 shadow-xl ring-1 ring-line md:p-8">
            <p class="rounded-2xl bg-brand-soft px-4 py-3 text-sm leading-relaxed text-ink">
                Peran tidak bisa dipilih sendiri: yang Anda isi hanyalah <strong>permintaan</strong>. Tidak ada email konfirmasi; hubungi operator BPBD di instansi Anda agar pendaftaran segera ditinjau.
            </p>

            <form class="mt-6 space-y-6" @submit.prevent="submit">
                <fieldset>
                    <legend class="text-sm font-semibold">Anda ingin bertugas sebagai</legend>
                    <div class="mt-2 grid gap-2">
                        <label
                            v-for="r in roles"
                            :key="r.value"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 has-[:checked]:border-trench has-[:checked]:bg-white"
                            :class="form.requested_role === r.value ? 'border-trench' : 'border-line'"
                        >
                            <input v-model="form.requested_role" type="radio" name="requested_role" :value="r.value" class="mt-1" />
                            <span>
                                <span class="block text-sm font-semibold">{{ r.label }}</span>
                                <span class="block text-xs text-muted">{{ ROLE_HELP[r.value] }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.requested_role" class="mt-1 text-sm text-quake-3" role="alert">{{ form.errors.requested_role }}</p>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-medium">Nama lengkap</label>
                        <input id="name" v-model="form.name" required autocomplete="name" :class="field" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-quake-3" role="alert">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-medium">Nomor telepon <span class="font-normal text-muted">(opsional)</span></label>
                        <input id="phone" v-model="form.phone_number" type="tel" autocomplete="tel" inputmode="tel" :class="field" />
                        <p v-if="form.errors.phone_number" class="mt-1 text-sm text-quake-3" role="alert">{{ form.errors.phone_number }}</p>
                    </div>
                </div>

                <div>
                    <label for="organization" class="block text-sm font-medium">Instansi atau posko tempat Anda bertugas</label>
                    <input id="organization" v-model="form.organization" required :class="field" placeholder="Contoh: BPBD Lumajang, atau Posko Balai Desa Sumbermujur" />
                    <p v-if="form.errors.organization" class="mt-1 text-sm text-quake-3" role="alert">{{ form.errors.organization }}</p>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium">Email</label>
                    <input id="email" v-model="form.email" type="email" required autocomplete="username" :class="field" />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-quake-3" role="alert">{{ form.errors.email }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="block text-sm font-medium">Kata sandi</label>
                        <input id="password" v-model="form.password" type="password" required autocomplete="new-password" :class="field" aria-describedby="pw-help" />
                        <p id="pw-help" class="mt-1 text-xs text-muted">Minimal 10 karakter, memuat huruf dan angka.</p>
                        <p v-if="form.errors.password" class="mt-1 text-sm text-quake-3" role="alert">{{ form.errors.password }}</p>
                    </div>
                    <div>
                        <label for="password2" class="block text-sm font-medium">Ulangi kata sandi</label>
                        <input id="password2" v-model="form.password_confirmation" type="password" required autocomplete="new-password" :class="field" />
                    </div>
                </div>

                <div>
                    <label for="note" class="block text-sm font-medium">Keterangan untuk operator <span class="font-normal text-muted">(opsional)</span></label>
                    <textarea id="note" v-model="form.request_note" rows="3" maxlength="500" :class="field" placeholder="Siapa yang bisa memastikan Anda, atau apa tugas Anda." />
                </div>

                <!-- Jebakan robot: tersembunyi dari manusia dan pembaca layar. -->
                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label>Jangan diisi <input v-model="form.website" tabindex="-1" autocomplete="off" /></label>
                </div>

                <button type="submit" class="sheen relative w-full overflow-hidden rounded-full bg-brand px-4 py-4 text-base font-extrabold text-white shadow-md transition-transform hover:scale-[1.01] active:scale-[0.99] disabled:opacity-60" :disabled="form.processing">
                    {{ form.processing ? 'Mengirim…' : 'Kirim pendaftaran' }}
                </button>

                <p class="text-sm text-muted">Sudah punya akun? <Link href="/masuk" class="font-semibold text-ink underline underline-offset-2">Masuk</Link></p>
            </form>
          </div>
        </div>
    </PublicLayout>
</template>
