<?php

use App\Enums\Role;
use App\Events\CampThreatenedEvent;
use App\Events\LogisticsAlertEvent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    // Otorisasi channel hanya berjalan dengan driver yang benar-benar menandatangani (bukan "null").
    config(['broadcasting.default' => 'reverb']);
    // Channel terdaftar saat boot pada driver "null" (phpunit.xml); daftarkan ulang pada driver yang baru.
    require base_path('routes/channels.php');

    $this->posko = lgCamp('PSK-A', 113.2, -8.1);
    $this->lain = lgCamp('PSK-B', 112.0, -7.0);

    $this->koordinator = User::factory()->create()->assignRole(Role::CampCoordinator->value);
    $this->posko->update(['coordinator_id' => $this->koordinator->id]);

    $this->operator = User::factory()->create()->assignRole(Role::BpbdOperator->value);
    $this->gudang = User::factory()->create()->assignRole(Role::WarehouseManager->value);
    $this->relawan = User::factory()->create()->assignRole(Role::FieldVolunteer->value);
});

function authChannel(string $channel): TestResponse
{
    return test()->postJson('/api/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678']);
}

it('menolak pengguna yang belum login mendengarkan channel private', function () {
    authChannel("private-camps.{$this->posko->id}")->assertUnauthorized();
    authChannel('private-logistics')->assertUnauthorized();
});

it('mengizinkan koordinator mendengarkan posko yang diampunya dan menandatangani tiketnya', function () {
    Sanctum::actingAs($this->koordinator);

    $res = authChannel("private-camps.{$this->posko->id}")->assertOk();

    expect($res->json('auth'))->toStartWith(config('broadcasting.connections.reverb.key').':');
});

it('menolak koordinator mendengarkan posko lain', function () {
    Sanctum::actingAs($this->koordinator);

    authChannel("private-camps.{$this->lain->id}")->assertForbidden();
});

it('mengizinkan petugas koordinasi mendengarkan posko mana pun', function (string $who) {
    Sanctum::actingAs($this->{$who});

    authChannel("private-camps.{$this->lain->id}")->assertOk();
    authChannel("private-camps.{$this->posko->id}")->assertOk();
})->with(['operator', 'gudang']);

it('menolak relawan lapangan mendengarkan posko mana pun', function () {
    Sanctum::actingAs($this->relawan);

    authChannel("private-camps.{$this->posko->id}")->assertForbidden();
});

it('menolak ID posko yang bukan UUID tanpa galat server', function () {
    Sanctum::actingAs($this->koordinator);

    authChannel('private-camps.bukan-uuid')->assertForbidden();
    authChannel("private-camps.1' OR '1'='1")->assertForbidden();
});

it('hanya mengizinkan petugas logistik ke channel logistics', function () {
    Sanctum::actingAs($this->operator);
    authChannel('private-logistics')->assertOk();

    Sanctum::actingAs($this->gudang);
    authChannel('private-logistics')->assertOk();

    Sanctum::actingAs($this->koordinator);
    authChannel('private-logistics')->assertForbidden();

    Sanctum::actingAs($this->relawan);
    authChannel('private-logistics')->assertForbidden();
});

it('menerima autentikasi lewat token Bearer (aplikasi lapangan) selain cookie sesi', function () {
    $token = $this->koordinator->createToken('ponsel')->plainTextToken;

    $this->withToken($token)->postJson('/api/broadcasting/auth', [
        'channel_name' => "private-camps.{$this->posko->id}", 'socket_id' => '1234.5678',
    ])->assertOk();
});

it('menyiarkan CampThreatenedEvent hanya ke channel posko yang bersangkutan', function () {
    $event = new CampThreatenedEvent($this->posko->id, ['id' => 'x'], ['name' => 'PSK-A'], 12.3);

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe("private-camps.{$this->posko->id}")
        ->and($event->broadcastAs())->toBe('camp.threatened')
        ->and($event->broadcastWith()['distance_km'])->toBe(12.3);
});

it('menyiarkan LogisticsAlertEvent ke channel logistics', function () {
    $event = new LogisticsAlertEvent(['id' => 'x'], [['name' => 'A', 'code' => 'A', 'distance_km' => 1.0]]);

    expect($event->broadcastOn()[0]->name)->toBe('private-logistics')
        ->and($event->broadcastAs())->toBe('logistics.alert');
});

it('mengizinkan relawan yang ditugaskan ke posko mendengarkan channel posko itu, bukan posko lain', function () {
    $this->posko->volunteers()->attach($this->relawan->id);
    Sanctum::actingAs($this->relawan);

    authChannel("private-camps.{$this->posko->id}")->assertOk();
    authChannel("private-camps.{$this->lain->id}")->assertForbidden();
});
