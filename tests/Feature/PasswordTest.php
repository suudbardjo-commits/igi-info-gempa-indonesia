<?php

use App\Enums\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Support\TemporaryPassword;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/** Membuat pengguna berperan dengan kata sandi yang diketahui. */
function staf(Role $role, string $email = 'staf@example.com', string $password = 'lama-12345x'): User
{
    return User::factory()->create(['email' => $email, 'password' => $password])->assignRole($role->value);
}

/** Sesi uji terikat ke kata sandi satu pengguna; saat berganti pengguna, sesi lama dibuang seperti di peramban baru. */
function masuk(User $user)
{
    // Satu aplikasi dipakai untuk banyak permintaan dalam tes; guard bawaan dan pengguna tersimpan dikembalikan seperti permintaan baru.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    test()->flushSession();

    return test()->actingAs($user);
}

describe('ganti kata sandi sendiri', function () {
    it('hanya untuk yang sudah masuk', function () {
        $this->postJson('/api/v1/me/password', [])->assertUnauthorized();
    });

    it('mengganti kata sandi dengan kata sandi lama yang benar', function () {
        $u = staf(Role::FieldVolunteer);

        masuk($u)->postJson('/api/v1/me/password', ['current_password' => 'lama-12345x', 'password' => 'baru-67890y', 'password_confirmation' => 'baru-67890y'])
            ->assertOk()->assertJsonPath('message', 'Kata sandi sudah diganti. Perangkat lain yang memakai akun ini harus masuk lagi.');

        $u->refresh();
        expect(Hash::check('baru-67890y', $u->password))->toBeTrue()->and(Hash::check('lama-12345x', $u->password))->toBeFalse();
    });

    it('menolak kata sandi lama yang salah dan tidak mengubah apa pun', function () {
        $u = staf(Role::FieldVolunteer);

        masuk($u)->postJson('/api/v1/me/password', ['current_password' => 'salah-12345x', 'password' => 'baru-67890y', 'password_confirmation' => 'baru-67890y'])
            ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'Kata sandi saat ini salah.');

        expect(Hash::check('lama-12345x', $u->fresh()->password))->toBeTrue();
    });

    it('menolak kata sandi baru yang lemah, tidak cocok, atau sama dengan yang lama, dengan pesan berbahasa Indonesia', function () {
        $u = staf(Role::FieldVolunteer);
        $kirim = fn (array $over) => masuk($u)->postJson('/api/v1/me/password', $over + ['current_password' => 'lama-12345x', 'password' => 'baru-67890y', 'password_confirmation' => 'baru-67890y']);

        $kirim(['password' => 'pendek1', 'password_confirmation' => 'pendek1'])->assertJsonPath('errors.password.0', 'Kata sandi minimal 10 karakter.');
        $kirim(['password' => 'hurufsajahurufsaja', 'password_confirmation' => 'hurufsajahurufsaja'])->assertJsonPath('errors.password.0', 'Kata sandi harus memuat huruf dan angka.');
        $kirim(['password' => '1234567890123', 'password_confirmation' => '1234567890123'])->assertJsonPath('errors.password.0', 'Kata sandi harus memuat huruf dan angka.');
        $kirim(['password_confirmation' => 'lain-67890y'])->assertJsonPath('errors.password.0', 'Konfirmasi kata sandi tidak sama.');
        $kirim(['password' => 'lama-12345x', 'password_confirmation' => 'lama-12345x'])->assertJsonValidationErrors('password');
        $kirim(['current_password' => ''])->assertJsonPath('errors.current_password.0', 'Kata sandi saat ini wajib diisi.');

        expect(Hash::check('lama-12345x', $u->fresh()->password))->toBeTrue();
    });

    it('mencabut token di perangkat lain tetapi mempertahankan token yang dipakai', function () {
        $u = staf(Role::FieldVolunteer);
        $dipakai = $u->createToken('hp ini')->plainTextToken;
        $u->createToken('hp lain');
        $u->createToken('tablet');

        $this->withToken($dipakai)->postJson('/api/v1/me/password', ['current_password' => 'lama-12345x', 'password' => 'baru-67890y', 'password_confirmation' => 'baru-67890y'])->assertOk();

        expect($u->tokens()->pluck('name')->all())->toBe(['hp ini']);
        $this->withToken($dipakai)->getJson('/api/v1/auth/me')->assertOk();
    });

    it('memutus sesi peramban di perangkat lain', function () {
        $u = staf(Role::FieldVolunteer);

        $this->post('/masuk', ['email' => $u->email, 'password' => 'lama-12345x'])->assertRedirect();
        $this->get('/akun')->assertOk();

        // Perangkat lain mengganti kata sandi akun ini; sesi ini tidak lagi sah.
        $u->forceFill(['password' => 'dari-perangkat-lain-1'])->save();

        // Permintaan berikutnya memuat pengguna dari database lagi (guard tes menyimpan instance lama, aplikasi sungguhan tidak).
        app('auth')->forgetGuards();
        $this->get('/akun')->assertRedirect('/masuk');
    });

    it('dibatasi 6 percobaan per menit supaya kata sandi lama tidak bisa ditebak', function () {
        $u = staf(Role::FieldVolunteer);
        masuk($u);

        foreach (range(1, 6) as $i) {
            $this->postJson('/api/v1/me/password', ['current_password' => "tebakan-{$i}9999", 'password' => 'baru-67890y', 'password_confirmation' => 'baru-67890y'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/me/password', ['current_password' => 'lama-12345x', 'password' => 'baru-67890y', 'password_confirmation' => 'baru-67890y'])->assertStatus(429);
        expect(Hash::check('lama-12345x', $u->fresh()->password))->toBeTrue();
    });
});

describe('daftar petugas aktif', function () {
    it('hanya untuk yang berhak meninjau', function () {
        $this->getJson('/api/v1/staff')->assertUnauthorized();
        masuk(staf(Role::FieldVolunteer))->getJson('/api/v1/staff')->assertForbidden();
        masuk(staf(Role::WarehouseManager, 'g@example.com'))->getJson('/api/v1/staff')->assertForbidden();
    });

    it('memuat yang disetujui saja, dengan tanda siapa yang boleh diatur ulang', function () {
        $op = staf(Role::BpbdOperator, 'op@example.com');
        $op2 = staf(Role::BpbdOperator, 'op2@example.com');
        $admin = staf(Role::SuperAdmin, 'admin@example.com');
        $relawan = staf(Role::FieldVolunteer, 'rel@example.com');
        $pending = User::factory()->create(['email' => 'pend@example.com']);
        $pending->forceFill(['account_status' => 'pending'])->save();

        $res = masuk($op)->getJson('/api/v1/staff')->assertOk();
        $by = collect($res->json('data'))->keyBy('email');

        expect($by->has('pend@example.com'))->toBeFalse()
            ->and($by['op@example.com']['can_reset'])->toBeFalse()   // diri sendiri
            ->and($by['op2@example.com']['can_reset'])->toBeFalse()  // operator lain: hanya super admin
            ->and($by['admin@example.com']['can_reset'])->toBeFalse() // super admin: hanya lewat terminal
            ->and($by['rel@example.com']['can_reset'])->toBeTrue();

        $by = collect(masuk($admin)->getJson('/api/v1/staff')->json('data'))->keyBy('email');
        expect($by['op@example.com']['can_reset'])->toBeTrue()->and($by['admin@example.com']['can_reset'])->toBeFalse();
    });

    it('tidak membocorkan kata sandi atau data sensitif', function () {
        staf(Role::FieldVolunteer, 'rel@example.com');

        $json = masuk(staf(Role::BpbdOperator, 'op@example.com'))->getJson('/api/v1/staff')->getContent();

        // Nama kolom seperti must_change_password boleh ada; yang tidak boleh adalah nilai kata sandi atau hash-nya.
        expect($json)->not->toContain('"password":')->and($json)->not->toContain('remember_token')->and($json)->not->toContain('$2y$');
    });
});

describe('atur ulang oleh operator', function () {
    it('menghasilkan kata sandi sementara yang sah, hanya sekali, dan mewajibkan penggantian', function () {
        $op = staf(Role::BpbdOperator, 'op@example.com');
        $target = staf(Role::FieldVolunteer, 'rel@example.com');
        $target->createToken('hp');

        $res = masuk($op)->postJson("/api/v1/staff/{$target->id}/reset-password")->assertOk();
        $temporary = $res->json('temporary_password');

        expect($temporary)->toBeString()->and(strlen($temporary))->toBe(12)
            ->and(Validator::make(['p' => $temporary], ['p' => [new StrongPassword]])->passes())->toBeTrue();

        $target->refresh();
        expect(Hash::check($temporary, $target->password))->toBeTrue()
            ->and(Hash::check('lama-12345x', $target->password))->toBeFalse()
            ->and($target->must_change_password)->toBeTrue()
            ->and($target->password_reset_by)->toBe($op->id)
            ->and($target->password_reset_at)->not->toBeNull()
            ->and($target->tokens()->count())->toBe(0);

        // Daftar petugas tidak pernah menampilkannya lagi.
        expect(masuk($op)->getJson('/api/v1/staff')->getContent())->not->toContain($temporary);
    });

    it('setiap pengaturan ulang menghasilkan kata sandi berbeda', function () {
        $op = staf(Role::BpbdOperator, 'op@example.com');
        $target = staf(Role::FieldVolunteer, 'rel@example.com');

        $a = masuk($op)->postJson("/api/v1/staff/{$target->id}/reset-password")->json('temporary_password');
        $b = masuk($op)->postJson("/api/v1/staff/{$target->id}/reset-password")->json('temporary_password');

        expect($a)->not->toBe($b);
    });

    it('menolak yang tidak boleh diatur ulang', function () {
        $op = staf(Role::BpbdOperator, 'op@example.com');
        $op2 = staf(Role::BpbdOperator, 'op2@example.com');
        $admin = staf(Role::SuperAdmin, 'admin@example.com');
        $pending = User::factory()->create(['email' => 'pend@example.com']);
        $pending->forceFill(['account_status' => 'pending'])->save();

        foreach ([$op, $op2, $admin, $pending] as $target) {
            masuk($op)->postJson("/api/v1/staff/{$target->id}/reset-password")->assertUnprocessable()->assertJsonValidationErrors('user');
        }

        masuk($admin)->postJson("/api/v1/staff/{$admin->id}/reset-password")->assertUnprocessable();

        // Tak satu pun kata sandi berubah.
        expect(Hash::check('lama-12345x', $op2->fresh()->password))->toBeTrue()->and($op2->fresh()->must_change_password)->toBeFalse();
    });

    it('super admin boleh mengatur ulang operator', function () {
        $op = staf(Role::BpbdOperator, 'op@example.com');

        masuk(staf(Role::SuperAdmin, 'admin@example.com'))->postJson("/api/v1/staff/{$op->id}/reset-password")->assertOk();

        expect($op->fresh()->must_change_password)->toBeTrue();
    });

    it('hanya operator yang boleh, dan petugas lain ditolak', function () {
        $target = staf(Role::FieldVolunteer, 'rel@example.com');

        $this->postJson("/api/v1/staff/{$target->id}/reset-password")->assertUnauthorized();
        masuk(staf(Role::WarehouseManager, 'g@example.com'))->postJson("/api/v1/staff/{$target->id}/reset-password")->assertForbidden();
        masuk(staf(Role::FieldVolunteer, 'r2@example.com'))->postJson("/api/v1/staff/{$target->id}/reset-password")->assertForbidden();

        expect($target->fresh()->must_change_password)->toBeFalse();
    });

    it('memutus sesi peramban lama milik akun yang diatur ulang', function () {
        $target = staf(Role::FieldVolunteer, 'rel@example.com');

        $this->post('/masuk', ['email' => $target->email, 'password' => 'lama-12345x'])->assertRedirect();
        $this->get('/akun')->assertOk();

        $temporary = masuk(staf(Role::BpbdOperator, 'op@example.com'))->postJson("/api/v1/staff/{$target->id}/reset-password")->json('temporary_password');

        // Sesi lama (peramban yang mungkin sudah dikuasai orang lain) tidak lagi sah; hanya kata sandi baru yang bisa masuk.
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->post('/masuk', ['email' => $target->email, 'password' => 'lama-12345x'])->assertSessionHasErrors('email');
        $this->post('/masuk', ['email' => $target->email, 'password' => $temporary])->assertRedirect('/akun');
    });
});

describe('akun berkata sandi sementara', function () {
    function diaturUlang(Role $role = Role::FieldVolunteer): array
    {
        $u = staf($role, 'rel@example.com');
        $temporary = masuk(staf(Role::BpbdOperator, 'op@example.com'))->postJson("/api/v1/staff/{$u->id}/reset-password")->json('temporary_password');

        app('auth')->forgetGuards();
        test()->flushSession();

        return [$u->fresh(), $temporary];
    }

    it('masuk lewat sesi diarahkan ke halaman Akun, bukan tujuan sebelumnya', function () {
        [$u, $temporary] = diaturUlang();

        $this->get('/lapangan')->assertRedirect('/masuk');
        $this->post('/masuk', ['email' => $u->email, 'password' => $temporary])->assertRedirect('/akun');
    });

    it('halaman kerja dialihkan ke Akun, sedangkan Akun dan keluar tetap terbuka', function () {
        [$u] = diaturUlang();
        masuk($u);

        $this->get('/lapangan')->assertRedirect('/akun');
        $this->get('/akun')->assertOk()->assertInertia(fn ($p) => $p->where('auth.user.must_change_password', true));
        $this->post('/keluar')->assertRedirect();
    });

    it('konsol logistik pun dialihkan untuk operator yang kata sandinya diatur ulang super admin', function () {
        $op = staf(Role::BpbdOperator, 'op@example.com');
        masuk(staf(Role::SuperAdmin, 'admin@example.com'))->postJson("/api/v1/staff/{$op->id}/reset-password")->assertOk();

        masuk($op->fresh())->get('/logistik')->assertRedirect('/akun');
    });

    it('API menjawab 403 berkode kecuali melihat diri, keluar, dan mengganti kata sandi', function () {
        [$u, $temporary] = diaturUlang();
        masuk($u);

        $this->getJson('/api/v1/field/overview')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/v1/me/overview')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->postJson('/api/v1/sync/field-logs', ['operations' => []])->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('must_change_password', true);

        // Data publik tetap terbuka.
        $this->getJson('/api/v1/disasters')->assertOk();
    });

    it('token dari masuk API juga hanya bisa dipakai untuk mengganti kata sandi', function () {
        [$u, $temporary] = diaturUlang();

        $token = $this->postJson('/api/v1/auth/token', ['email' => $u->email, 'password' => $temporary, 'device_name' => 'hp'])
            ->assertOk()->assertJsonPath('user.must_change_password', true)->json('token');

        $this->withToken($token)->getJson('/api/v1/field/overview')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->withToken($token)->postJson('/api/v1/me/password', ['current_password' => $temporary, 'password' => 'pilihan-saya-77', 'password_confirmation' => 'pilihan-saya-77'])->assertOk();
        $this->withToken($token)->getJson('/api/v1/field/overview')->assertOk();
    });

    it('setelah diganti sendiri, akses normal kembali dan kata sandi sementara tidak berlaku lagi', function () {
        [$u, $temporary] = diaturUlang();
        masuk($u);

        $this->postJson('/api/v1/me/password', ['current_password' => $temporary, 'password' => 'pilihan-saya-77', 'password_confirmation' => 'pilihan-saya-77'])->assertOk();

        $u->refresh();
        expect($u->must_change_password)->toBeFalse()->and(Hash::check('pilihan-saya-77', $u->password))->toBeTrue()->and(Hash::check($temporary, $u->password))->toBeFalse();

        app('auth')->shouldUse('web'); // permintaan API sebelumnya menjadikan 'sanctum' guard bawaan di aplikasi tes yang sama
        $this->get('/lapangan')->assertOk();

        app('auth')->forgetGuards();
        $this->flushSession();
        $this->post('/masuk', ['email' => $u->email, 'password' => $temporary])->assertSessionHasErrors('email');
        $this->post('/masuk', ['email' => $u->email, 'password' => 'pilihan-saya-77'])->assertRedirect();
    });

    it('kata sandi sementara tidak boleh dipertahankan sebagai kata sandi baru', function () {
        [$u, $temporary] = diaturUlang();

        masuk($u)->postJson('/api/v1/me/password', ['current_password' => $temporary, 'password' => $temporary, 'password_confirmation' => $temporary])->assertUnprocessable();

        expect($u->fresh()->must_change_password)->toBeTrue();
    });
});

describe('pembangkit kata sandi sementara', function () {
    it('selalu sah, 12 karakter, tanpa karakter yang mudah tertukar, dan tidak berulang', function () {
        $seen = [];

        foreach (range(1, 300) as $i) {
            $p = TemporaryPassword::make();
            $seen[$p] = true;

            expect(strlen($p))->toBe(12)
                ->and($p)->toMatch('/[a-z]/')->toMatch('/[A-Z]/')->toMatch('/[0-9]/')
                ->and($p)->not->toMatch('/[0OIl1]/');
            expect(Validator::make(['p' => $p], ['p' => [new StrongPassword]])->passes())->toBeTrue();
        }

        expect(count($seen))->toBe(300);
    });
});

describe('bahasa pesan validasi', function () {
    it('pesan isian wajib dan format berbahasa Indonesia di formulir daftar', function () {
        $res = $this->postJson('/daftar', ['email' => 'bukan-email'])->assertUnprocessable();

        expect($res->json('errors.name.0'))->toBe('Nama wajib diisi.')
            ->and($res->json('errors.email.0'))->toBe('Email harus berupa alamat email yang sah.')
            ->and($res->json('errors.organization.0'))->toBe('Instansi atau posko wajib diisi.');
    });
});
