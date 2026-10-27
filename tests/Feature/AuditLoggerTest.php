<?php

use App\Models\User;
use App\Services\Logistics\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function audit(string $action = 'TEST', array $extra = []): App\Models\LogisticsAuditLog
{
    return app(AuditLogger::class)->record(
        $action, 'item', (string) Str::uuid(),
        $extra['old'] ?? null, $extra['new'] ?? ['qty' => 5.5, 'nama' => 'Beras'],
        $extra['user'] ?? null, $extra['ip'] ?? '10.0.0.1', $extra['geo'] ?? null,
    );
}

it('menautkan tiap baris ke hash baris sebelumnya', function () {
    $a = audit('A');
    $b = audit('B');
    $c = audit('C');

    expect($a->prev_hash)->toBeNull()
        ->and($b->prev_hash)->toBe($a->hash)
        ->and($c->prev_hash)->toBe($b->hash)
        ->and($a->hash)->toHaveLength(64);
});

it('menyimpan pengguna, IP, dan koordinat GPS beserta akurasinya', function () {
    $user = User::factory()->create();

    $row = audit('RECEIVE', ['user' => $user, 'geo' => ['lat' => -8.1335, 'lng' => 113.2246, 'accuracy' => 12.5]]);

    $db = DB::table('logistics_audit_logs')
        ->selectRaw('user_id, ip_address, geo_accuracy_m, ST_X(geo_point) lng, ST_Y(geo_point) lat')
        ->where('id', $row->id)->first();

    expect($db->user_id)->toBe($user->id)
        ->and($db->ip_address)->toBe('10.0.0.1')
        ->and((float) $db->lng)->toEqualWithDelta(113.2246, 0.00001)
        ->and((float) $db->lat)->toEqualWithDelta(-8.1335, 0.00001)
        ->and((float) $db->geo_accuracy_m)->toBe(12.5);
});

it('menyatakan rantai utuh saat tidak ada yang diubah', function () {
    audit('A', ['old' => ['status' => 'draft'], 'new' => ['status' => 'dispatched']]);
    audit('B', ['geo' => ['lat' => -7.25, 'lng' => 112.75]]);
    audit('C');

    expect(app(AuditLogger::class)->verify())->toBeNull();
    $this->artisan('audit:verify')->expectsOutputToContain('utuh')->assertSuccessful();
});

it('tetap utuh untuk angka bulat bertipe float, array kosong, dan nilai null di dalam data', function () {
    // PHP menyimpan 0.0 sebagai 0 di JSON; hash harus dihitung dari bentuk yang tersimpan.
    audit('A', ['new' => ['jarak_m' => 0.0, 'qty' => 10.0, 'kosong' => [], 'catatan' => null, 'bersarang' => ['b' => 1, 'a' => 2.50]]]);

    expect(app(AuditLogger::class)->verify())->toBeNull();
});

it('menyatakan rantai utuh juga saat tabel masih kosong', function () {
    expect(app(AuditLogger::class)->verify())->toBeNull();
});

it('menolak UPDATE dan DELETE lewat trigger database', function () {
    $row = audit();

    expect(fn () => DB::table('logistics_audit_logs')->where('id', $row->id)->update(['action' => 'UBAH']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('mendeteksi baris yang diubah walau trigger dilumpuhkan', function () {
    $first = audit('A');
    $second = audit('B', ['new' => ['qty' => 10]]);
    audit('C');

    // Simulasi penyerang dengan akses DBA: matikan trigger lalu ubah isi baris kedua.
    DB::statement('ALTER TABLE logistics_audit_logs DISABLE TRIGGER trg_audit_append_only');
    DB::table('logistics_audit_logs')->where('id', $second->id)->update(['new_values' => json_encode(['qty' => 99999])]);

    expect(app(AuditLogger::class)->verify())->toBe($second->id);
    $this->artisan('audit:verify')->expectsOutputToContain("id {$second->id}")->assertFailed();
    expect($first->id)->toBeLessThan($second->id);
});

it('mendeteksi baris yang dihapus dari tengah rantai', function () {
    audit('A');
    $middle = audit('B');
    $last = audit('C');

    DB::statement('ALTER TABLE logistics_audit_logs DISABLE TRIGGER trg_audit_append_only');
    DB::table('logistics_audit_logs')->where('id', $middle->id)->delete();

    expect(app(AuditLogger::class)->verify())->toBe($last->id);
});
