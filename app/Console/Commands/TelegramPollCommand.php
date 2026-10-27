<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('telegram:poll')]
#[Description('Baca pesan masuk ke bot Telegram (tautan akun lewat /start KODE, berhenti lewat /stop)')]
class TelegramPollCommand extends Command
{
    private const OFFSET_KEY = 'telegram:update-offset';

    public function handle(TelegramClient $telegram, TelegramLinkService $links): int
    {
        if (! $telegram->enabled()) {
            $this->line('Bot Telegram belum dikonfigurasi (isi TELEGRAM_BOT_TOKEN di .env).');

            return self::SUCCESS;
        }

        $updates = $telegram->getUpdates((int) Cache::get(self::OFFSET_KEY, 0));

        if ($updates === null) {
            $this->warn('Gagal mengambil pesan dari Telegram.');

            return self::FAILURE;
        }

        foreach ($updates as $update) {
            // Offset maju dulu: pesan yang bermasalah tidak boleh diproses ulang selamanya.
            Cache::forever(self::OFFSET_KEY, $update['update_id'] + 1);

            $message = $update['message'] ?? null;

            // Hanya chat pribadi; bot yang dimasukkan ke grup diabaikan.
            if (! $message || ($message['chat']['type'] ?? null) !== 'private') {
                continue;
            }

            $this->reply($telegram, $links, (int) $message['chat']['id'], trim((string) ($message['text'] ?? '')));
        }

        $this->info(count($updates).' pesan diproses.');

        return self::SUCCESS;
    }

    private function reply(TelegramClient $telegram, TelegramLinkService $links, int $chatId, string $text): void
    {
        if (preg_match('/^\/start(?:@\w+)?\s+(\S.*)$/u', $text, $m)) {
            $result = $links->confirm($m[1], $chatId);

            $telegram->sendMessage($chatId, match ($result['status']) {
                'linked' => 'Telegram terhubung ke akun <b>'.TelegramClient::escape($result['user']->name).'</b>. '
                    .'Anda akan menerima peringatan gempa untuk posko yang Anda ampu. Kirim /stop kapan saja untuk berhenti.',
                'chat_in_use' => 'Telegram ini sudah terhubung ke akun lain. Kirim /stop dulu bila ingin memindahkannya.',
                'throttled' => 'Terlalu banyak kode yang salah. Coba lagi satu jam lagi.',
                default => 'Kode tidak valid atau sudah kedaluwarsa. Buat kode baru di halaman Akun lalu kirim lagi.',
            });

            return;
        }

        if (preg_match('/^\/stop(?:@\w+)?$/u', $text)) {
            $telegram->sendMessage($chatId, $links->unlinkChat($chatId)
                ? 'Notifikasi dihentikan. Telegram ini tidak lagi terhubung ke akun mana pun.'
                : 'Telegram ini memang belum terhubung ke akun mana pun.');

            return;
        }

        $telegram->sendMessage($chatId, "Bot ini mengirim peringatan gempa untuk posko pengungsian.\n\n"
            ."Untuk menghubungkan akun: buka halaman Akun di aplikasi, minta kode, lalu kirim <code>/start KODE</code> ke sini.\n"
            .'Kirim /stop untuk berhenti menerima notifikasi.');
    }
}
