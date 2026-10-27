<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function applyPayload(array $over = []): array
{
    return $over + [
        'name' => 'Siti Rahma',
        'email' => 'siti@example.com',
        'phone_number' => '081234567890',
        'password' => 'rahasia-12345',
        'password_confirmation' => 'rahasia-12345',
        'requested_role' => 'field-volunteer',
        'organization' => 'BPBD Lumajang',
        'request_note' => 'Relawan sejak 2024.',
    ];
}

function reviewer(Role $role = Role::BpbdOperator): User
{
    return User::factory()->create()->assignRole($role->value);
}

function applicant(string $role = 'field-volunteer', string $email = 'siti@example.com'): User
{
    // Pendaftar adalah tamu: sesi dan guard dari pengguna yang sedang dipakai tes dilepas dulu.
    app('auth')->forgetGuards();
    test()->flushSession();
    test()->post('/daftar', applyPayload(['email' => $email, 'requested_role' => $role]))->assertSessionHasNoErrors();

    return User::where('email', $email)->firstOrFail();
}

describe('pendaftaran', function () {
    it('menampilkan formulir hanya tiga peran yang boleh diminta', function () {
        $this->get('/daftar')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Daftar')
            ->where('roles', fn ($roles) => collect($roles)->pluck('value')->sort()->values()->all() === ['camp-coordinator', 'field-volunteer', 'warehouse-manager']));
    });

    it('membuat akun pending tanpa peran, dengan kata sandi ter-hash', function () {
        $this->post('/daftar', applyPayload())->assertRedirect('/daftar/terkirim');

        $user = User::where('email', 'siti@example.com')->first();

        expect($user->account_status)->toBe('pending')
            ->and($user->getRoleNames())->toHaveCount(0)
            ->and($user->requested_role)->toBe('field-volunteer')
            ->and($user->password)->not->toBe('rahasia-12345')
            ->and(Hash::check('rahasia-12345', $user->password))->toBeTrue();
    });

    it('tidak bisa menaikkan hak sendiri lewat isian tambahan atau peran terlarang', function () {
        $this->post('/daftar', applyPayload([
            'account_status' => 'approved', 'roles' => ['super-admin'], 'role' => 'super-admin', 'reviewed_by' => 1,
        ]))->assertRedirect('/daftar/terkirim');

        $user = User::where('email', 'siti@example.com')->first();
        expect($user->account_status)->toBe('pending')->and($user->getRoleNames())->toHaveCount(0)->and($user->reviewed_by)->toBeNull();

        foreach (['bpbd-operator', 'super-admin', 'ngawur'] as $role) {
            $this->post('/daftar', applyPayload(['email' => "{$role}@example.com", 'requested_role' => $role]))->assertSessionHasErrors('requested_role');
            expect(User::where('email', "{$role}@example.com")->exists())->toBeFalse();
        }
    });

    it('menolak kata sandi lemah, yang tidak cocok, dan isian wajib yang kosong', function () {
        $this->post('/daftar', applyPayload(['password' => 'pendek1', 'password_confirmation' => 'pendek1']))->assertSessionHasErrors('password');
        $this->post('/daftar', applyPayload(['password' => 'hurufsajahurufsaja', 'password_confirmation' => 'hurufsajahurufsaja']))->assertSessionHasErrors('password');
        $this->post('/daftar', applyPayload(['password_confirmation' => 'lain-12345']))->assertSessionHasErrors('password');
        $this->post('/daftar', applyPayload(['organization' => '']))->assertSessionHasErrors('organization');
        $this->post('/daftar', applyPayload(['phone_number' => 'abc']))->assertSessionHasErrors('phone_number');

        expect(User::where('email', 'siti@example.com')->exists())->toBeFalse();
    });

    it('email yang sudah ada dijawab sama dan tidak mengubah akun lama (tidak membocorkan siapa terdaftar)', function () {
        $old = User::factory()->create(['email' => 'siti@example.com', 'password' => 'kata-sandi-lama1']);

        $this->post('/daftar', applyPayload())->assertRedirect('/daftar/terkirim');

        $old->refresh();
        expect(User::where('email', 'siti@example.com')->count())->toBe(1)
            ->and(Hash::check('kata-sandi-lama1', $old->password))->toBeTrue()
            ->and($old->account_status)->toBe('approved');
    });

    it('menganggap huruf besar pada email sama dengan huruf kecil', function () {
        $this->post('/daftar', applyPayload(['email' => 'Siti@Example.COM']))->assertRedirect('/daftar/terkirim');
        $this->post('/daftar', applyPayload(['email' => 'siti@example.com']));

        expect(User::where('email', 'siti@example.com')->count())->toBe(1);
    });

    it('jebakan robot yang terisi: terlihat berhasil tetapi tidak membuat akun', function () {
        $this->post('/daftar', applyPayload(['website' => 'http://spam.example']))->assertRedirect('/daftar/terkirim');

        expect(User::where('email', 'siti@example.com')->exists())->toBeFalse();
    });

    it('halaman terkirim hanya bisa dilihat setelah mengirim, dan tamu yang sudah masuk dialihkan dari formulir', function () {
        $this->get('/daftar/terkirim')->assertRedirect('/daftar');

        $this->post('/daftar', applyPayload());
        $this->get('/daftar/terkirim')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Daftar/Terkirim'));

        $this->actingAs(reviewer())->get('/daftar')->assertRedirect('/');
    });
});

describe('akun belum disetujui tidak bisa masuk', function () {
    it('lewat sesi: kata sandi benar diberi tahu statusnya, kata sandi salah tetap pesan umum', function () {
        applicant();

        $this->post('/masuk', ['email' => 'siti@example.com', 'password' => 'rahasia-12345'])
            ->assertSessionHasErrors(['email' => 'Pendaftaran akun ini belum disetujui. Operator BPBD akan meninjaunya; coba masuk lagi setelah dikabari.']);
        $this->assertGuest();

        $this->post('/masuk', ['email' => 'siti@example.com', 'password' => 'salah-salah-1'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
        $this->post('/masuk', ['email' => 'tidak-ada@example.com', 'password' => 'salah-salah-1'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
    });

    it('lewat token API tidak mendapat token', function () {
        applicant();

        $this->postJson('/api/v1/auth/token', ['email' => 'siti@example.com', 'password' => 'rahasia-12345', 'device_name' => 'hp'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        expect(\Laravel\Sanctum\PersonalAccessToken::count())->toBe(0);
    });

    it('akun biasa yang sudah ada tetap bisa masuk (kolom status bawaan approved)', function () {
        User::factory()->create(['email' => 'lama@example.com', 'password' => 'kata-sandi-lama1'])->assignRole(Role::FieldVolunteer->value);

        $this->post('/masuk', ['email' => 'lama@example.com', 'password' => 'kata-sandi-lama1'])->assertRedirect();
        $this->assertAuthenticated();
    });
});

describe('peninjauan oleh operator', function () {
    it('hanya operator yang boleh melihat dan meninjau', function () {
        $u = applicant();

        $this->getJson('/api/v1/registrations')->assertUnauthorized();
        $this->actingAs(User::factory()->create()->assignRole(Role::FieldVolunteer->value))->getJson('/api/v1/registrations')->assertForbidden();
        $this->actingAs(User::factory()->create()->assignRole(Role::WarehouseManager->value))->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'field-volunteer'])->assertForbidden();

        $this->actingAs(reviewer())->getJson('/api/v1/registrations')->assertOk()
            ->assertJsonCount(1, 'pending')->assertJsonPath('pending.0.email', 'siti@example.com')
            ->assertJsonMissingPath('pending.0.password');
    });

    it('menyetujui relawan: peran, penugasan posko, dan bisa langsung masuk', function () {
        $camp = lgCamp('PSK-A', 113.2, -8.1);
        $u = applicant();
        $op = reviewer();

        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'field-volunteer', 'camp_id' => $camp->id])->assertOk();

        $u->refresh();
        expect($u->account_status)->toBe('approved')->and($u->hasRole('field-volunteer'))->toBeTrue()
            ->and($u->reviewed_by)->toBe($op->id)->and($camp->fresh()->volunteers->pluck('id')->all())->toBe([$u->id]);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/masuk', ['email' => 'siti@example.com', 'password' => 'rahasia-12345'])->assertRedirect();
        $this->assertAuthenticatedAs($u);
    });

    it('operator menetapkan peran sendiri, bukan yang diminta pendaftar', function () {
        $camp = lgCamp('PSK-A', 113.2, -8.1);
        $u = applicant('camp-coordinator');

        $this->actingAs(reviewer())->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'field-volunteer', 'camp_id' => $camp->id])->assertOk();

        expect($u->fresh()->getRoleNames()->all())->toBe(['field-volunteer']);
    });

    it('koordinator mengambil posko yang masih kosong, dan menolak posko yang sudah punya koordinator', function () {
        $camp = lgCamp('PSK-A', 113.2, -8.1);
        $first = applicant('camp-coordinator', 'a@example.com');
        $second = applicant('camp-coordinator', 'b@example.com');
        $op = reviewer();

        $this->actingAs($op)->postJson("/api/v1/registrations/{$first->id}/approve", ['role' => 'camp-coordinator', 'camp_id' => $camp->id])->assertOk();
        expect($camp->fresh()->coordinator_id)->toBe($first->id);

        $this->actingAs($op)->postJson("/api/v1/registrations/{$second->id}/approve", ['role' => 'camp-coordinator', 'camp_id' => $camp->id])
            ->assertUnprocessable()->assertJsonValidationErrors('camp_id');

        $second->refresh();
        expect($second->account_status)->toBe('pending')->and($second->getRoleNames())->toHaveCount(0)->and($camp->fresh()->coordinator_id)->toBe($first->id);
    });

    it('pengelola gudang wajib memilih gudang yang masih kosong', function () {
        $w = lgWarehouse('GD-A', 112.7, -7.2);
        $u = applicant('warehouse-manager');
        $op = reviewer();

        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'warehouse-manager'])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'warehouse-manager', 'warehouse_id' => $w->id])->assertOk();

        expect($w->fresh()->manager_id)->toBe($u->id);

        $other = applicant('warehouse-manager', 'c@example.com');
        $this->actingAs($op)->postJson("/api/v1/registrations/{$other->id}/approve", ['role' => 'warehouse-manager', 'warehouse_id' => $w->id])->assertUnprocessable();
    });

    it('relawan dan koordinator wajib menyebut posko', function () {
        $u = applicant();

        $this->actingAs(reviewer())->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'field-volunteer'])->assertUnprocessable()->assertJsonValidationErrors('camp_id');

        expect($u->fresh()->account_status)->toBe('pending');
    });

    it('operator tidak boleh mengangkat operator lain atau admin; super admin boleh mengangkat operator', function () {
        $u = applicant();

        foreach (['bpbd-operator', 'super-admin'] as $role) {
            $this->actingAs(reviewer())->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => $role])->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        expect($u->fresh()->getRoleNames())->toHaveCount(0);

        $this->actingAs(reviewer(Role::SuperAdmin))->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'bpbd-operator'])->assertOk();
        expect($u->fresh()->hasRole('bpbd-operator'))->toBeTrue();

        $this->actingAs(reviewer(Role::SuperAdmin))->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'super-admin'])->assertUnprocessable();
    });

    it('pendaftaran yang sudah ditinjau tidak bisa ditinjau lagi', function () {
        $camp = lgCamp('PSK-A', 113.2, -8.1);
        $u = applicant();
        $op = reviewer();

        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'field-volunteer', 'camp_id' => $camp->id])->assertOk();
        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/approve", ['role' => 'field-volunteer', 'camp_id' => $camp->id])->assertUnprocessable();
        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/reject", ['reason' => 'terlambat'])->assertUnprocessable();

        expect($u->fresh()->account_status)->toBe('approved');
    });

    it('menolak dengan alasan; akun tetap tidak bisa masuk dan melihat pesan penolakan', function () {
        $u = applicant();
        $op = reviewer();

        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/reject", ['reason' => ''])->assertUnprocessable();
        $this->actingAs($op)->postJson("/api/v1/registrations/{$u->id}/reject", ['reason' => 'Tidak dikenal oleh BPBD setempat'])->assertOk();

        expect($u->fresh()->account_status)->toBe('rejected')->and($u->fresh()->reject_reason)->toBe('Tidak dikenal oleh BPBD setempat');

        $list = $this->actingAs($op)->getJson('/api/v1/registrations')->assertOk();
        expect($list->json('pending'))->toBe([])->and($list->json('recent'))->toHaveCount(1);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/masuk', ['email' => 'siti@example.com', 'password' => 'rahasia-12345'])
            ->assertSessionHasErrors(['email' => 'Pendaftaran akun ini tidak disetujui. Hubungi operator BPBD bila ini keliru.']);
        $this->assertGuest();
    });

    it('halaman peninjauan hanya untuk yang berhak', function () {
        $this->get('/logistik/petugas')->assertRedirect('/masuk');
        $this->actingAs(User::factory()->create()->assignRole(Role::WarehouseManager->value))->get('/logistik/petugas')->assertForbidden();
        $this->actingAs(reviewer())->get('/logistik/petugas')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Logistik/Petugas'));
    });
});

describe('perintah user:create', function () {
    it('membuat akun aktif dengan peran, dan menolak kata sandi lemah atau email ganda', function () {
        $this->artisan('user:create', ['email' => 'admin2@example.com', 'name' => 'Admin Dua', '--role' => 'bpbd-operator'])
            ->expectsQuestion('Kata sandi (minimal 10 karakter, huruf dan angka)', 'kata-sandi-kuat1')
            ->assertSuccessful();

        $u = User::where('email', 'admin2@example.com')->first();
        expect($u->account_status)->toBe('approved')->and($u->hasRole('bpbd-operator'))->toBeTrue()
            ->and(Hash::check('kata-sandi-kuat1', $u->password))->toBeTrue();

        $this->artisan('user:create', ['email' => 'lemah@example.com', 'name' => 'Lemah Sekali', '--role' => 'field-volunteer'])
            ->expectsQuestion('Kata sandi (minimal 10 karakter, huruf dan angka)', 'pendek')
            ->assertFailed();
        expect(User::where('email', 'lemah@example.com')->exists())->toBeFalse();

        $this->artisan('user:create', ['email' => 'admin2@example.com', 'name' => 'Ganda Lagi', '--role' => 'field-volunteer'])
            ->expectsQuestion('Kata sandi (minimal 10 karakter, huruf dan angka)', 'kata-sandi-kuat1')
            ->assertFailed();

        $this->artisan('user:create', ['email' => 'x@example.com', 'name' => 'Peran Salah', '--role' => 'dewa'])->assertFailed();
    });
});
