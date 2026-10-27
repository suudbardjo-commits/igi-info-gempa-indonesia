<?php

use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use App\Services\Telegram\TelegramOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

const TG_TOKEN = '123456:SECRET-TOKEN-ABC';

beforeEach(function () {
    config(['services.telegram.bot_token' => TG_TOKEN, 'services.telegram.bot_username' => 'bencana_test_bot']);
    Cache::flush();
    $this->tg = app(TelegramClient::class);
    $this->links = app(TelegramLinkService::class);
});

function tgUpdate(int $id, int $chat, string $text, string $type = 'private'): array
{
    return ['update_id' => $id, 'message' => ['chat' => ['id' => $chat, 'type' => $type], 'text' => $text]];
}

function tgFakeBot(array $updates = []): void
{
    Http::fake([
        'api.telegram.org/*/getUpdates*' => Http::response(['ok' => true, 'result' => $updates]),
        'api.telegram.org/*/sendMessage' => Http::response(['ok' => true]),
    ]);
}

describe('klien', function () {
    it('tidak memanggil Telegram sama sekali kalau token belum diisi', function () {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        $result = $this->tg->sendMessage(42, 'halo');

        expect($result->outcome)->toBe(TelegramOutcome::Disabled)->and($this->tg->enabled())->toBeFalse();
        Http::assertNothingSent();
    });

    it('mengirim pesan HTML ke chat yang benar', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        expect($this->tg->sendMessage(777, '<b>Gempa</b>')->ok())->toBeTrue();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 777 && $r['parse_mode'] === 'HTML' && $r['text'] === '<b>Gempa</b>');
    });

    it('meng-escape teks dari luar supaya tidak menyisipkan tag', function () {
        expect(TelegramClient::escape('Gempa <script>&"x"'))->toBe('Gempa &lt;script&gt;&amp;"x"');
    });

    it('memetakan respons Telegram ke hasil yang bisa ditindaklanjuti', function (int $status, array $body, TelegramOutcome $expected) {
        Http::fake(['api.telegram.org/*' => Http::response($body, $status)]);

        expect($this->tg->sendMessage(1, 'x')->outcome)->toBe($expected);
    })->with([
        'pengguna memblokir bot' => [403, ['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], TelegramOutcome::Blocked],
        'chat tidak ada' => [400, ['ok' => false, 'description' => 'Bad Request: chat not found'], TelegramOutcome::Blocked],
        'kena batas laju' => [429, ['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 7]], TelegramOutcome::RetryLater],
        'galat server' => [502, ['ok' => false, 'description' => 'Bad Gateway'], TelegramOutcome::RetryLater],
        'permintaan salah' => [400, ['ok' => false, 'description' => 'Bad Request: can\'t parse entities'], TelegramOutcome::Failed],
    ]);

    it('membawa retry_after dari Telegram saat kena batas laju', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'x', 'parameters' => ['retry_after' => 9]], 429)]);

        expect($this->tg->sendMessage(1, 'x')->retryAfter)->toBe(9);
    });

    it('mencatat log kritis tanpa token saat token bot ditolak (401), dan hasilnya gagal tanpa percobaan ulang', function () {
        Log::spy();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized: invalid token specified'], 401)]);

        $result = $this->tg->sendMessage(1, 'x');

        expect($result->outcome)->toBe(TelegramOutcome::Failed);
        Log::shouldHaveReceived('critical')->once()->withArgs(
            fn ($message, $context = []) => str_contains($message, 'TELEGRAM_BOT_TOKEN')
                && ! str_contains($message.json_encode($context), 'SECRET-TOKEN'),
        );
    });

    it('menganggap gangguan jaringan sebagai sementara, tanpa membocorkan token', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error: https://api.telegram.org/bot'.TG_TOKEN.'/sendMessage timed out'));

        $result = $this->tg->sendMessage(1, 'x');

        expect($result->outcome)->toBe(TelegramOutcome::RetryLater)
            ->and((string) $result->error)->not->toContain('SECRET-TOKEN');
    });

    it('tidak menaruh token di hasil galat maupun log saat gagal', function () {
        Log::spy();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $result = $this->tg->sendMessage(1, 'x');

        expect((string) $result->error)->not->toContain('SECRET-TOKEN');
        Log::shouldNotHaveReceived('warning', fn ($m, $ctx = []) => str_contains(json_encode($ctx), 'SECRET-TOKEN'));
    });
});

describe('tautan akun', function () {
    it('menerbitkan kode 8 karakter tanpa huruf yang mudah tertukar, dan hanya menyimpan hash-nya', function () {
        $user = User::factory()->create();

        ['code' => $code] = $this->links->issueCode($user);

        expect($code)->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{8}$/');

        $row = TelegramLink::where('user_id', $user->id)->first();
        expect($row->code_hash)->not->toContain($code)->and($row->code_hash)->toHaveLength(64);
    });

    it('menautkan chat lewat kode yang benar, tidak peka huruf besar-kecil atau tanda hubung', function () {
        $user = User::factory()->create();
        ['code' => $code] = $this->links->issueCode($user);

        $messy = strtolower(substr($code, 0, 4).' - '.substr($code, 4));
        $result = $this->links->confirm($messy, 555);

        expect($result['status'])->toBe('linked')->and($result['user']->id)->toBe($user->id);
        expect(TelegramLink::where('user_id', $user->id)->first())->chat_id->toBe(555);
    });

    it('hanya berlaku sekali', function () {
        $user = User::factory()->create();
        ['code' => $code] = $this->links->issueCode($user);

        expect($this->links->confirm($code, 1)['status'])->toBe('linked');
        expect($this->links->confirm($code, 2)['status'])->toBe('invalid');
    });

    it('kedaluwarsa setelah 15 menit', function () {
        $user = User::factory()->create();
        ['code' => $code] = $this->links->issueCode($user);

        $this->travel(16)->minutes();

        expect($this->links->confirm($code, 1)['status'])->toBe('invalid');
    });

    it('kode baru membatalkan kode lama', function () {
        $user = User::factory()->create();
        ['code' => $lama] = $this->links->issueCode($user);
        ['code' => $baru] = $this->links->issueCode($user);

        expect($this->links->confirm($lama, 1)['status'])->toBe('invalid');
        expect($this->links->confirm($baru, 1)['status'])->toBe('linked');
    });

    it('menolak chat yang sudah terhubung ke akun lain', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        ['code' => $kodeA] = $this->links->issueCode($a);
        $this->links->confirm($kodeA, 900);

        ['code' => $kodeB] = $this->links->issueCode($b);

        expect($this->links->confirm($kodeB, 900)['status'])->toBe('chat_in_use');
    });

    it('memblokir chat yang menebak kode terlalu sering, bahkan bila tebakan berikutnya benar', function () {
        $user = User::factory()->create();
        ['code' => $code] = $this->links->issueCode($user);

        foreach (range(1, 5) as $i) {
            expect($this->links->confirm("SALAH{$i}XX", 31337)['status'])->toBe('invalid');
        }

        expect($this->links->confirm($code, 31337)['status'])->toBe('throttled');
        // Chat lain tidak terpengaruh.
        expect($this->links->confirm($code, 42)['status'])->toBe('linked');
    });

    it('menolak kode kosong', function () {
        expect($this->links->confirm('  - ', 1)['status'])->toBe('invalid');
    });

    it('memutus tautan', function () {
        $user = User::factory()->create();
        ['code' => $code] = $this->links->issueCode($user);
        $this->links->confirm($code, 5);

        $this->links->unlinkUser($user);

        expect(TelegramLink::where('user_id', $user->id)->first()->isLinked())->toBeFalse();
    });
});

describe('perintah telegram:poll', function () {
    it('tidak melakukan apa-apa tanpa token', function () {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        $this->artisan('telegram:poll')->expectsOutputToContain('belum dikonfigurasi')->assertSuccessful();
        Http::assertNothingSent();
    });

    it('menautkan akun saat menerima /start KODE dan membalas dengan nama akun yang di-escape', function () {
        $user = User::factory()->create(['name' => 'Budi <b>Santoso</b>']);
        ['code' => $code] = $this->links->issueCode($user);
        tgFakeBot([tgUpdate(10, 4242, "/start {$code}")]);

        $this->artisan('telegram:poll')->assertSuccessful();

        expect(TelegramLink::where('user_id', $user->id)->first()->chat_id)->toBe(4242);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 4242
            && str_contains($r['text'], 'Budi &lt;b&gt;Santoso&lt;/b&gt;') && ! str_contains($r['text'], 'Budi <b>Santoso'));
    });

    it('memajukan offset supaya pesan tidak diproses dua kali', function () {
        tgFakeBot([tgUpdate(10, 1, 'halo'), tgUpdate(11, 1, 'halo lagi')]);

        $this->artisan('telegram:poll')->assertSuccessful();

        expect(Cache::get('telegram:update-offset'))->toBe(12);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/getUpdates?offset=0&timeout=0&allowed_updates=%5B%22message%22%5D'));
    });

    it('menjawab kode salah dengan petunjuk, bukan galat', function () {
        tgFakeBot([tgUpdate(1, 9, '/start NGAWUR22')]);

        $this->artisan('telegram:poll')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Kode tidak valid'));
    });

    it('menjawab pesan lain dengan bantuan cara menautkan akun', function () {
        tgFakeBot([tgUpdate(1, 9, 'halo bot')]);

        $this->artisan('telegram:poll')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => str_contains((string) ($r['text'] ?? ''), '/start KODE'));
    });

    it('memutus tautan lewat /stop', function () {
        $user = User::factory()->create();
        ['code' => $code] = $this->links->issueCode($user);
        $this->links->confirm($code, 808);
        tgFakeBot([tgUpdate(1, 808, '/stop')]);

        $this->artisan('telegram:poll')->assertSuccessful();

        expect(TelegramLink::where('user_id', $user->id)->first()->isLinked())->toBeFalse();
    });

    it('mengabaikan pesan dari grup', function () {
        tgFakeBot([tgUpdate(1, -100123, '/start ABCDEFGH', 'supergroup')]);

        $this->artisan('telegram:poll')->assertSuccessful();

        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage'));
    });

    it('melaporkan kegagalan mengambil pesan sebagai galat', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->artisan('telegram:poll')->assertFailed();
    });
});

describe('API tautan', function () {
    it('menolak pengguna yang belum login', function () {
        $this->getJson('/api/v1/telegram')->assertUnauthorized();
        $this->postJson('/api/v1/telegram/link-code')->assertUnauthorized();
    });

    it('memberi status, kode tautan, lalu memutus tautan', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/telegram')->assertOk()
            ->assertJson(['bot_configured' => true, 'bot_username' => 'bencana_test_bot', 'linked' => false]);

        $code = $this->postJson('/api/v1/telegram/link-code')->assertCreated()->json('code');
        $this->links->confirm($code, 321);

        $this->getJson('/api/v1/telegram')->assertJsonPath('linked', true);
        $this->deleteJson('/api/v1/telegram')->assertOk();
        $this->getJson('/api/v1/telegram')->assertJsonPath('linked', false);
    });

    it('menjelaskan bahwa bot belum siap kalau token kosong', function () {
        config(['services.telegram.bot_token' => null]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/telegram')->assertJsonPath('bot_configured', false);
        $this->postJson('/api/v1/telegram/link-code')->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'belum disiapkan'));
    });

    it('tidak pernah menampilkan hash kode di status', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/telegram/link-code');

        expect($this->getJson('/api/v1/telegram')->getContent())->not->toContain('hash');
    });
});
