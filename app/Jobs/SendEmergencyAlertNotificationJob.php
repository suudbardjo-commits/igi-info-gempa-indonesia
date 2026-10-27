<?php

namespace App\Jobs;

use App\Models\AlertDelivery;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use App\Services\Telegram\TelegramOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Mengirim satu peringatan Telegram. Aman dijalankan ulang: pengiriman yang sudah berhasil tidak
 * dikirim lagi, dan galat sementara (batas laju, jaringan) dicoba lagi dengan jeda.
 */
class SendEmergencyAlertNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> detik */
    public array $backoff = [5, 15, 45, 120, 300];

    public function __construct(public string $deliveryId, public string $html)
    {
        // Antrean khusus yang diproses lebih dulu daripada pekerjaan biasa.
        $this->onQueue('alerts');
    }

    public function handle(TelegramClient $telegram, TelegramLinkService $links): void
    {
        $delivery = AlertDelivery::find($this->deliveryId);

        if (! $delivery || $delivery->status === 'sent') {
            return;
        }

        $chatId = DB::table('telegram_links')->where('user_id', $delivery->user_id)->value('chat_id');

        if ($chatId === null) {
            $delivery->update(['status' => 'skipped', 'error' => 'Telegram belum ditautkan atau sudah diputus.']);

            return;
        }

        $delivery->increment('attempts');
        $result = $telegram->sendMessage((int) $chatId, $this->html);

        match ($result->outcome) {
            TelegramOutcome::Sent => $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]),
            TelegramOutcome::Disabled => $delivery->update(['status' => 'skipped', 'error' => 'Bot Telegram nonaktif.']),
            TelegramOutcome::Blocked => $this->handleBlocked($delivery, $links, (string) $result->error),
            TelegramOutcome::Failed => $delivery->update(['status' => 'failed', 'error' => $result->error]),
            TelegramOutcome::RetryLater => $this->retryLater($delivery, $result->retryAfter, (string) $result->error),
        };
    }

    /** Pengguna memblokir bot: cabut tautan supaya tidak terus mencoba, dan catat. */
    private function handleBlocked(AlertDelivery $delivery, TelegramLinkService $links, string $error): void
    {
        $delivery->update(['status' => 'failed', 'error' => $error]);

        if ($user = $delivery->user_id) {
            DB::table('telegram_links')->where('user_id', $user)->update(['chat_id' => null, 'linked_at' => null]);
        }
    }

    private function retryLater(AlertDelivery $delivery, ?int $retryAfter, string $error): void
    {
        $delivery->update(['error' => $error]);

        if ($this->attempts() >= $this->tries) {
            $delivery->update(['status' => 'failed']);

            return;
        }

        // Hormati jeda yang diminta Telegram saat kena batas laju.
        $this->release($retryAfter ?? ($this->backoff[$this->attempts() - 1] ?? 300));
    }

    public function failed(\Throwable $e): void
    {
        AlertDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'error' => 'Job gagal: '.class_basename($e)]);
    }
}
