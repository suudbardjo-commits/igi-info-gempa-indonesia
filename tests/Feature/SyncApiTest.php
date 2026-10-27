<?php

use App\Enums\CampStatus;
use App\Enums\DispatchStatus;
use App\Enums\Role;
use App\Events\CampConditionReportedEvent;
use App\Events\CampDemandCreatedEvent;
use App\Models\CampDemand;
use App\Models\FieldReport;
use App\Models\ReliefDispatch;
use App\Models\ReliefDispatchItem;
use App\Models\User;
use App\Services\Logistics\DemandCreator;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->posko = lgCamp('PSK-A', 113.2, -8.1, capacity: 100);
    $this->lain = lgCamp('PSK-B', 112.0, -7.0);
    $this->beras = lgItem('BERAS');

    $this->koordinator = User::factory()->create()->assignRole(Role::CampCoordinator->value);
    $this->relawan = User::factory()->create()->assignRole(Role::FieldVolunteer->value);
    $this->relawanLain = User::factory()->create()->assignRole(Role::FieldVolunteer->value);
    $this->operator = User::factory()->create()->assignRole(Role::BpbdOperator->value);

    $this->posko->update(['coordinator_id' => $this->koordinator->id]);
    $this->posko->volunteers()->attach($this->relawan->id);
});

/** Satu operasi dari perangkat. recorded_at default 5 menit lalu. */
function op(string $type, array $payload, ?string $at = null, ?string $id = null): array
{
    return ['id' => $id ?? (string) Str::uuid7(), 'type' => $type, 'recorded_at' => $at ?? now()->subMinutes(5)->toIso8601String(), 'payload' => $payload];
}

function occupancyOp(string $campId, int $occupants, ?string $at = null, array $extra = [], ?string $id = null): array
{
    return op('occupancy_report', ['camp_id' => $campId, 'occupants' => $occupants] + $extra, $at, $id);
}

function sync(array ...$ops): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/sync/field-logs', ['operations' => $ops]);
}

function statusOf(Illuminate\Testing\TestResponse $res, int $i = 0): string
{
    return $res->json("results.{$i}.status");
}

describe('akses dan validasi permintaan', function () {
    it('menolak yang belum login', function () {
        $this->postJson('/api/v1/sync/field-logs', ['operations' => []])->assertUnauthorized();
    });

    it('menolak permintaan kosong, terlalu besar, atau berformat salah', function () {
        Sanctum::actingAs($this->koordinator);

        $this->postJson('/api/v1/sync/field-logs', ['operations' => []])->assertUnprocessable();
        $this->postJson('/api/v1/sync/field-logs', [])->assertUnprocessable();

        $many = array_fill(0, 101, occupancyOp($this->posko->id, 1));
        $this->postJson('/api/v1/sync/field-logs', ['operations' => $many])->assertUnprocessable();

        sync(['id' => 'bukan-uuid', 'type' => 'occupancy_report', 'recorded_at' => now()->toIso8601String(), 'payload' => []])->assertUnprocessable();
        sync(op('jenis_ngawur', []))->assertUnprocessable()->assertJsonValidationErrors('operations.0.type');
    });
});

describe('laporan jumlah pengungsi', function () {
    it('menyimpan laporan dan memperbarui angka posko', function () {
        Sanctum::actingAs($this->koordinator);
        $at = now()->subMinutes(10)->setMicroseconds(0);

        $res = sync(occupancyOp($this->posko->id, 42, $at->toIso8601String(), ['note' => 'Tambah 10 keluarga', 'latitude' => -8.1001, 'longitude' => 113.2001, 'accuracy' => 12]))->assertOk();

        expect(statusOf($res))->toBe('applied');

        $camp = $this->posko->fresh();
        expect($camp->current_occupants)->toBe(42)
            ->and($camp->occupants_updated_at->equalTo($at))->toBeTrue();

        $report = FieldReport::first();
        expect($report->type)->toBe('occupancy')->and($report->payload)->toMatchArray(['occupants' => 42, 'note' => 'Tambah 10 keluarga'])
            ->and($report->user_id)->toBe($this->koordinator->id)
            ->and($report->geo_accuracy_m)->toBe(12.0)
            ->and($report->synced_at)->not->toBeNull();
    });

    it('tidak menjalankan operasi yang sama dua kali saat dikirim ulang', function () {
        Sanctum::actingAs($this->koordinator);
        $op = occupancyOp($this->posko->id, 30);

        expect(statusOf(sync($op)))->toBe('applied');

        // Perangkat tidak menerima jawabannya (jaringan putus) dan mengirim ulang, beberapa kali.
        foreach (range(1, 3) as $i) {
            $again = sync($op)->assertOk();
            expect(statusOf($again))->toBe('duplicate')->and($again->json('results.0.original_status'))->toBe('applied');
        }

        expect(FieldReport::count())->toBe(1)->and(DB::table('sync_operations')->count())->toBe(1);
    });

    it('laporan yang lebih tua tetapi tiba belakangan tidak menimpa angka yang lebih baru, namun tetap tersimpan', function () {
        Sanctum::actingAs($this->koordinator);

        sync(occupancyOp($this->posko->id, 80, now()->subMinutes(10)->toIso8601String()));
        $late = sync(occupancyOp($this->posko->id, 20, now()->subHours(3)->toIso8601String()))->assertOk();

        expect(statusOf($late))->toBe('applied_stale')->and($late->json('results.0.message'))->toContain('lebih baru');
        expect($this->posko->fresh()->current_occupants)->toBe(80)->and(FieldReport::count())->toBe(2);
    });

    it('urutan di dalam satu kiriman tidak menentukan hasil: yang terbaru menurut jam perangkat yang berlaku', function () {
        Sanctum::actingAs($this->koordinator);

        $baru = occupancyOp($this->posko->id, 90, now()->subMinutes(5)->toIso8601String());
        $lama = occupancyOp($this->posko->id, 10, now()->subHours(2)->toIso8601String());

        // Dikirim dengan yang baru di depan.
        $res = sync($baru, $lama)->assertOk();

        expect($this->posko->fresh()->current_occupants)->toBe(90)
            // Hasil dikembalikan menurut urutan kiriman, bukan urutan proses.
            ->and($res->json('results.0.id'))->toBe($baru['id'])->and($res->json('results.1.id'))->toBe($lama['id'])
            // Diproses dari yang paling lama, jadi keduanya sempat berlaku berurutan.
            ->and(statusOf($res, 0))->toBe('applied')->and(statusOf($res, 1))->toBe('applied');
    });

    it('melaporkan operasi yang muncul dua kali dalam satu kiriman: yang pertama applied, pengulangannya duplicate', function () {
        Sanctum::actingAs($this->koordinator);
        $op = occupancyOp($this->posko->id, 44);

        $res = sync($op, $op)->assertOk();

        expect(array_column($res->json('results'), 'status'))->toBe(['applied', 'duplicate']);
        expect(FieldReport::count())->toBe(1);
    });

    it('menganggap dua laporan di detik yang sama sebagai: yang pertama diproses menang, yang kedua basi', function () {
        Sanctum::actingAs($this->koordinator);
        $at = now()->subMinutes(5)->toIso8601String();

        expect(statusOf(sync(occupancyOp($this->posko->id, 50, $at))))->toBe('applied');
        expect(statusOf(sync(occupancyOp($this->posko->id, 60, $at))))->toBe('applied_stale');
        expect($this->posko->fresh()->current_occupants)->toBe(50);
    });

    it('menandai posko penuh saat mencapai kapasitas, dan aktif lagi saat turun', function () {
        Sanctum::actingAs($this->koordinator);

        sync(occupancyOp($this->posko->id, 100, now()->subMinutes(30)->toIso8601String()));
        expect($this->posko->fresh()->status)->toBe(CampStatus::Full);

        sync(occupancyOp($this->posko->id, 60, now()->subMinutes(10)->toIso8601String()));
        expect($this->posko->fresh()->status)->toBe(CampStatus::Active);
    });

    it('tidak mengubah status posko yang ditutup atau dikosongkan', function () {
        $this->posko->update(['status' => CampStatus::Closed]);
        Sanctum::actingAs($this->koordinator);

        sync(occupancyOp($this->posko->id, 500));

        expect($this->posko->fresh()->status)->toBe(CampStatus::Closed)->and($this->posko->fresh()->current_occupants)->toBe(500);
    });

    it('menolak jumlah negatif, tidak bulat, atau tidak masuk akal, dan mengingat penolakannya', function (mixed $bad) {
        Sanctum::actingAs($this->koordinator);
        $op = occupancyOp($this->posko->id, 1);
        $op['payload']['occupants'] = $bad;

        $res = sync($op)->assertOk();

        expect(statusOf($res))->toBe('rejected')->and($res->json('results.0.message'))->not->toBeEmpty();
        expect(FieldReport::count())->toBe(0);

        // Dikirim ulang: perangkat mendapat jawaban yang sama dan berhenti mencoba.
        expect(statusOf(sync($op)))->toBe('duplicate');
        expect(sync($op)->json('results.0.original_status'))->toBe('rejected');
    })->with([-5, 1.5, 'banyak', 9_999_999]);
});

describe('hak akses per posko', function () {
    it('mengizinkan koordinator dan relawan yang ditugaskan', function (string $who) {
        Sanctum::actingAs($this->{$who});

        expect(statusOf(sync(occupancyOp($this->posko->id, 10))))->toBe('applied');
    })->with(['koordinator', 'relawan']);

    it('menolak relawan yang tidak ditugaskan, relawan posko lain, dan operator', function (string $who) {
        Sanctum::actingAs($this->{$who});

        $res = sync(occupancyOp($this->posko->id, 10));

        expect(statusOf($res))->toBe('rejected')->and($res->json('results.0.message'))->toContain('tidak berhak');
        expect($this->posko->fresh()->current_occupants)->toBe(0)->and(FieldReport::count())->toBe(0);
    })->with(['relawanLain', 'operator']);

    it('menolak melapor untuk posko lain walau koordinator di posko sendiri', function () {
        Sanctum::actingAs($this->koordinator);

        expect(statusOf(sync(occupancyOp($this->lain->id, 10))))->toBe('rejected');
    });

    it('menolak posko yang tidak ada tanpa galat server', function () {
        Sanctum::actingAs($this->koordinator);

        $res = sync(occupancyOp((string) Str::uuid(), 10));

        expect(statusOf($res))->toBe('rejected')->and($res->json('results.0.message'))->toContain('tidak ditemukan');
    });

    it('tidak membiarkan pengguna lain memakai ID operasi yang sudah ada, dan tidak membocorkan isinya', function () {
        $op = occupancyOp($this->posko->id, 40);
        Sanctum::actingAs($this->koordinator);
        sync($op);

        Sanctum::actingAs($this->relawan);
        $res = sync($op)->assertOk();

        expect(statusOf($res))->toBe('rejected')->and($res->json('results.0.message'))->toBe('ID operasi sudah dipakai.')
            ->and(json_encode($res->json('results')))->not->toContain('occupants');
        expect(FieldReport::count())->toBe(1);
    });
});

describe('jam perangkat', function () {
    it('memotong waktu di masa depan ke sekarang supaya tidak mengunci pembaruan berikutnya', function () {
        Sanctum::actingAs($this->koordinator);

        sync(occupancyOp($this->posko->id, 70, now()->addHours(5)->toIso8601String()));

        // Laporan berikutnya yang benar harus tetap bisa berlaku.
        $next = sync(occupancyOp($this->posko->id, 75, now()->addMinutes(1)->toIso8601String()));
        $stored = $this->posko->fresh()->occupants_updated_at;

        expect($stored->lte(now()->addMinutes(1)))->toBeTrue();
        expect(statusOf($next))->toBeIn(['applied', 'applied_stale']);
    });

    it('menolak laporan yang lebih tua dari 14 hari', function () {
        Sanctum::actingAs($this->koordinator);

        $res = sync(occupancyOp($this->posko->id, 10, now()->subDays(15)->toIso8601String()));

        expect(statusOf($res))->toBe('rejected')->and($res->json('results.0.message'))->toContain('terlalu lama');
    });

    it('menerima laporan yang tertahan offline beberapa jam dan menyimpan selisihnya', function () {
        Sanctum::actingAs($this->koordinator);

        sync(occupancyOp($this->posko->id, 55, now()->subHours(6)->toIso8601String()));

        $report = FieldReport::first();
        expect($report->recorded_at->diffInHours($report->synced_at, true))->toBeGreaterThanOrEqual(5);
    });
});

describe('laporan kondisi', function () {
    it('menyimpan laporan dan menyiarkan kondisi darurat yang baru ke konsol', function () {
        Event::fake([CampConditionReportedEvent::class]);
        Sanctum::actingAs($this->relawan);

        $res = sync(op('condition_report', ['camp_id' => $this->posko->id, 'severity' => 'darurat', 'note' => 'Atap tenda utama roboh']));

        expect(statusOf($res))->toBe('applied');
        Event::assertDispatched(CampConditionReportedEvent::class, fn ($e) => $e->camp['code'] === 'PSK-A' && $e->note === 'Atap tenda utama roboh');
        expect(FieldReport::first()->payload)->toMatchArray(['severity' => 'darurat']);
    });

    it('tidak menyiarkan kondisi darurat yang sudah lama (baru terkirim setelah offline)', function () {
        Event::fake([CampConditionReportedEvent::class]);
        Sanctum::actingAs($this->relawan);

        sync(op('condition_report', ['camp_id' => $this->posko->id, 'severity' => 'darurat', 'note' => 'Banjir'], now()->subHours(8)->toIso8601String()));

        Event::assertNotDispatched(CampConditionReportedEvent::class);
        expect(FieldReport::count())->toBe(1);
    });

    it('mewajibkan catatan untuk kondisi darurat, tetapi tidak untuk aman atau waspada', function () {
        Sanctum::actingAs($this->relawan);

        expect(statusOf(sync(op('condition_report', ['camp_id' => $this->posko->id, 'severity' => 'darurat']))))->toBe('rejected');
        expect(statusOf(sync(op('condition_report', ['camp_id' => $this->posko->id, 'severity' => 'aman']))))->toBe('applied');
        expect(statusOf(sync(op('condition_report', ['camp_id' => $this->posko->id, 'severity' => 'waspada']))))->toBe('applied');
        expect(statusOf(sync(op('condition_report', ['camp_id' => $this->posko->id, 'severity' => 'kacau']))))->toBe('rejected');
    });
});

describe('permintaan kebutuhan', function () {
    $body = fn ($t) => ['camp_id' => $t->posko->id, 'item_id' => $t->beras->id, 'quantity_requested' => '120.5', 'urgency_level' => 'critical', 'notes' => 'Habis'];

    it('mengajukan permintaan memakai ID operasi sebagai ID permintaan, tanpa ganda saat dikirim ulang', function () use ($body) {
        Event::fake([CampDemandCreatedEvent::class]);
        Sanctum::actingAs($this->koordinator);
        $op = op('demand', $body($this));

        expect(statusOf(sync($op)))->toBe('applied');
        expect(statusOf(sync($op)))->toBe('duplicate');
        expect(statusOf(sync($op)))->toBe('duplicate');

        expect(CampDemand::count())->toBe(1)->and(CampDemand::first()->id)->toBe($op['id'])
            ->and(CampDemand::first()->quantity_requested)->toBe(120.5);
        Event::assertDispatchedTimes(CampDemandCreatedEvent::class, 1);
    });

    it('menolak relawan (hanya koordinator yang boleh mengajukan kebutuhan)', function () use ($body) {
        Sanctum::actingAs($this->relawan);

        expect(statusOf(sync(op('demand', $body($this)))))->toBe('rejected');
        expect(CampDemand::count())->toBe(0);
    });

    it('menolak barang yang tidak ada dan jumlah yang tidak masuk akal', function () use ($body) {
        Sanctum::actingAs($this->koordinator);

        expect(statusOf(sync(op('demand', ['item_id' => (string) Str::uuid()] + $body($this)))))->toBe('rejected');
        expect(statusOf(sync(op('demand', ['quantity_requested' => '-3'] + $body($this)))))->toBe('rejected');
        expect(statusOf(sync(op('demand', ['urgency_level' => 'darurat-sekali'] + $body($this)))))->toBe('rejected');
        expect(CampDemand::count())->toBe(0);
    });

    it('menolak ID permintaan yang sudah dipakai posko lain lewat jalur API biasa dengan 409', function () {
        $demand = lgDemand($this->lain, $this->beras, 10);
        $this->lain->update(['coordinator_id' => $this->koordinator->id]);
        Sanctum::actingAs($this->koordinator);

        $this->postJson("/api/v1/camps/{$this->posko->id}/demands", [
            'id' => $demand->id, 'item_id' => $this->beras->id, 'quantity_requested' => 5, 'urgency_level' => 'high',
        ])->assertStatus(409);
    });
});

describe('penerimaan kiriman', function () {
    function dispatchedTo($t): ReliefDispatch
    {
        $gudang = lgWarehouse('GDG-A', 113.3, -8.1);
        $inv = lgStock($gudang, $t->beras, 'B-1', 300, now()->addMonths(3));
        $dispatch = lgDispatch($gudang, $t->posko, DispatchStatus::Dispatched);
        ReliefDispatchItem::create(['dispatch_id' => $dispatch->id, 'inventory_id' => $inv->id, 'item_id' => $t->beras->id, 'quantity_dispatched' => 200]);

        return $dispatch;
    }

    it('menerima kiriman dengan lokasi dan waktu yang dicatat saat di posko', function () {
        $dispatch = dispatchedTo($this);
        Sanctum::actingAs($this->koordinator);
        $arrived = now()->subHours(2)->setMicroseconds(0);

        $res = sync(op('dispatch_receipt', ['dispatch_id' => $dispatch->id, 'latitude' => -8.1, 'longitude' => 113.2, 'accuracy' => 15, 'notes' => 'Lengkap'], $arrived->toIso8601String()));

        expect(statusOf($res))->toBe('applied');

        $dispatch->refresh();
        expect($dispatch->status)->toBe(DispatchStatus::Received)
            ->and($dispatch->received_by)->toBe($this->koordinator->id)
            ->and($dispatch->arrival_time->equalTo($arrived))->toBeTrue();   // waktu tiba menurut perangkat, bukan saat sinkron
        expect((float) DB::table('camp_inventories')->value('quantity_on_hand'))->toBe(200.0);
    });

    it('menolak penerimaan yang lokasinya di luar geofence, dan tidak mengubah stok', function () {
        $dispatch = dispatchedTo($this);
        Sanctum::actingAs($this->koordinator);

        $res = sync(op('dispatch_receipt', ['dispatch_id' => $dispatch->id, 'latitude' => -7.25, 'longitude' => 112.75, 'accuracy' => 10]));

        expect(statusOf($res))->toBe('rejected')->and($res->json('results.0.message'))->toContain('di luar batas 500 m');
        expect($dispatch->fresh()->status)->toBe(DispatchStatus::Dispatched)->and(DB::table('camp_inventories')->count())->toBe(0);
    });

    it('tidak menerima dua kali: pengiriman ulang operasi yang sama duplikat, operasi baru untuk kiriman yang sama ditolak', function () {
        $dispatch = dispatchedTo($this);
        Sanctum::actingAs($this->koordinator);
        $payload = ['dispatch_id' => $dispatch->id, 'latitude' => -8.1, 'longitude' => 113.2, 'accuracy' => 10];
        $op = op('dispatch_receipt', $payload);

        expect(statusOf(sync($op)))->toBe('applied');
        expect(statusOf(sync($op)))->toBe('duplicate');

        $other = sync(op('dispatch_receipt', $payload));
        expect(statusOf($other))->toBe('rejected')->and($other->json('results.0.message'))->toContain('received');
        expect((float) DB::table('camp_inventories')->value('quantity_on_hand'))->toBe(200.0);
    });

    it('menolak relawan menerima kiriman', function () {
        $dispatch = dispatchedTo($this);
        Sanctum::actingAs($this->relawan);

        expect(statusOf(sync(op('dispatch_receipt', ['dispatch_id' => $dispatch->id, 'latitude' => -8.1, 'longitude' => 113.2]))))->toBe('rejected');
        expect($dispatch->fresh()->status)->toBe(DispatchStatus::Dispatched);
    });

    it('mencatat selisih jumlah diterima', function () {
        $dispatch = dispatchedTo($this);
        Sanctum::actingAs($this->koordinator);
        $line = $dispatch->items()->value('id');

        sync(op('dispatch_receipt', [
            'dispatch_id' => $dispatch->id, 'latitude' => -8.1, 'longitude' => 113.2,
            'received' => [['dispatch_item_id' => $line, 'quantity' => 150]],
        ]));

        expect((float) DB::table('camp_inventories')->value('quantity_on_hand'))->toBe(150.0);
    });
});

describe('ketahanan terhadap kegagalan', function () {
    it('satu operasi buruk tidak membatalkan yang lain dalam kiriman yang sama', function () {
        Sanctum::actingAs($this->koordinator);

        $res = sync(
            occupancyOp($this->posko->id, 10, now()->subMinutes(30)->toIso8601String()),
            occupancyOp($this->posko->id, -1, now()->subMinutes(20)->toIso8601String()),
            occupancyOp($this->lain->id, 5, now()->subMinutes(15)->toIso8601String()),
            occupancyOp($this->posko->id, 25, now()->subMinutes(10)->toIso8601String()),
        )->assertOk();

        expect(array_column($res->json('results'), 'status'))->toBe(['applied', 'rejected', 'rejected', 'applied']);
        expect($this->posko->fresh()->current_occupants)->toBe(25)->and(FieldReport::count())->toBe(2);
    });

    it('kegagalan tak terduga di tengah operasi dibatalkan bersih dan boleh dikirim ulang', function () {
        Sanctum::actingAs($this->koordinator);
        $this->mock(DemandCreator::class, fn ($m) => $m->shouldReceive('create')->once()->andThrow(new RuntimeException('database putus')));

        $op = op('demand', ['camp_id' => $this->posko->id, 'item_id' => $this->beras->id, 'quantity_requested' => 10, 'urgency_level' => 'high']);
        $res = sync($op)->assertOk();

        expect(statusOf($res))->toBe('retry')->and($res->json('results.0.message'))->toContain('dicoba lagi');
        // Klaim ikut batal, sehingga pengiriman ulang tidak dianggap duplikat.
        expect(DB::table('sync_operations')->where('id', $op['id'])->count())->toBe(0)->and(CampDemand::count())->toBe(0);

        // Setelah masalahnya hilang, operasi yang sama berhasil.
        app()->forgetInstance(DemandCreator::class);
        $this->app->bind(DemandCreator::class, fn () => new DemandCreator);

        expect(statusOf(sync($op)))->toBe('applied');
        expect(CampDemand::count())->toBe(1);
    });

    it('tidak membocorkan detail galat internal ke perangkat', function () {
        Sanctum::actingAs($this->koordinator);
        $this->mock(DemandCreator::class, fn ($m) => $m->shouldReceive('create')->andThrow(new RuntimeException('SQLSTATE rahasia: tabel x')));

        $res = sync(op('demand', ['camp_id' => $this->posko->id, 'item_id' => $this->beras->id, 'quantity_requested' => 10, 'urgency_level' => 'high']));

        expect($res->getContent())->not->toContain('SQLSTATE')->not->toContain('rahasia');
    });

    it('memproses 100 operasi dalam satu kiriman', function () {
        Sanctum::actingAs($this->koordinator);

        $ops = collect(range(1, 100))->map(fn ($i) => occupancyOp($this->posko->id, $i, now()->subMinutes(200 - $i)->toIso8601String()))->all();
        $res = sync(...$ops)->assertOk();

        expect(array_unique(array_column($res->json('results'), 'status')))->toBe(['applied']);
        expect($this->posko->fresh()->current_occupants)->toBe(100)->and(FieldReport::count())->toBe(100);
    });
});

describe('data untuk aplikasi lapangan', function () {
    it('memberi posko beserta peran, daftar barang, dan kiriman yang menuju posko yang diampu koordinator', function () {
        $dispatch = dispatchedTo($this);

        Sanctum::actingAs($this->koordinator);
        $res = $this->getJson('/api/v1/field/overview')->assertOk();

        expect($res->json('camps.0.role'))->toBe('koordinator')->and($res->json('camps.0.code'))->toBe('PSK-A')
            ->and(array_column($res->json('items'), 'sku'))->toContain('BERAS')
            ->and($res->json('incoming'))->toHaveCount(1)->and($res->json('incoming.0.id'))->toBe($dispatch->id)
            ->and($res->json('incoming.0.can.receive'))->toBeTrue();
    });

    it('memberi relawan posko tugasnya tanpa kiriman masuk', function () {
        dispatchedTo($this);

        Sanctum::actingAs($this->relawan);
        $res = $this->getJson('/api/v1/field/overview')->assertOk();

        expect($res->json('camps.0.role'))->toBe('relawan')->and($res->json('incoming'))->toBe([]);
    });

    it('memberi daftar kosong bagi relawan yang belum ditugaskan', function () {
        Sanctum::actingAs($this->relawanLain);

        expect($this->getJson('/api/v1/field/overview')->json('camps'))->toBe([]);
    });
});

describe('umpan laporan untuk operator', function () {
    it('menampilkan laporan terbaru dengan selisih waktu offline', function () {
        Sanctum::actingAs($this->koordinator);
        sync(occupancyOp($this->posko->id, 33, now()->subHours(4)->toIso8601String(), ['latitude' => -8.1, 'longitude' => 113.2, 'accuracy' => 9]));

        Sanctum::actingAs($this->operator);
        $res = $this->getJson('/api/v1/field-reports')->assertOk();

        expect($res->json('data.0.camp.code'))->toBe('PSK-A')->and($res->json('data.0.payload.occupants'))->toBe(33)
            ->and($res->json('data.0.delay_seconds'))->toBeGreaterThan(3 * 3600)
            ->and($res->json('data.0.location.accuracy_m'))->toEqual(9);
    });

    it('menyaring menurut jenis, dan menolak yang bukan petugas koordinasi', function () {
        Sanctum::actingAs($this->relawan);
        sync(occupancyOp($this->posko->id, 5));
        sync(op('condition_report', ['camp_id' => $this->posko->id, 'severity' => 'aman']));

        $this->getJson('/api/v1/field-reports')->assertForbidden();

        Sanctum::actingAs($this->operator);
        expect($this->getJson('/api/v1/field-reports?type=condition')->json('data'))->toHaveCount(1);
        expect($this->getJson('/api/v1/field-reports')->json('meta.total'))->toBe(2);
    });
});

describe('penugasan relawan dan halaman lapangan', function () {
    it('menugaskan dan mencabut relawan lewat perintah', function () {
        $this->artisan('camp:assign-volunteer', ['camp' => 'PSK-B', 'email' => $this->relawanLain->email])->assertSuccessful();
        expect($this->lain->volunteers()->count())->toBe(1);

        $this->artisan('camp:assign-volunteer', ['camp' => 'PSK-B', 'email' => $this->relawanLain->email, '--remove' => true])->assertSuccessful();
        expect($this->lain->volunteers()->count())->toBe(0);

        $this->artisan('camp:assign-volunteer', ['camp' => 'TIDAK-ADA', 'email' => $this->relawan->email])->assertFailed();
    });

    it('membuka halaman lapangan bagi koordinator dan relawan, dan menolak operator', function () {
        $this->actingAs($this->koordinator)->get('/lapangan')->assertOk();
        $this->actingAs($this->relawan)->get('/lapangan')->assertOk();
        $this->actingAs($this->operator)->get('/lapangan')->assertForbidden();
    });

    it('mengarahkan koordinator dan relawan ke aplikasi lapangan setelah masuk', function () {
        $this->koordinator->update(['password' => 'rahasia-123']);

        $this->post('/masuk', ['email' => $this->koordinator->email, 'password' => 'rahasia-123'])->assertRedirect('/lapangan');
    });
});
