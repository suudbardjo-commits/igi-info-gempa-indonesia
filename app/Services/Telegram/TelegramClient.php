<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien tipis untuk Telegram Bot API.
 *
 * Token ada di URL setiap permintaan, jadi galat TIDAK PERNAH dilempar sebagai exception HTTP
 * (pesannya memuat URL) dan tidak ada URL yang dicatat ke log.
 */
class TelegramClient
{
    public function enabled(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /** Teks harus HTML yang sudah di-escape lewat self::escape() untuk bagian yang berasal dari luar. */
    public function sendMessage(int|string $chatId, string $html): TelegramResult
    {
        if (! $this->enabled()) {
            return new TelegramResult(TelegramOutcome::Disabled);
        }

        try {
            $response = Http::timeout(10)->asJson()->post($this->url('sendMessage'), [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (ConnectionException) {
            return new TelegramResult(TelegramOutcome::RetryLater, error: 'Tidak bisa terhubung ke Telegram.');
        }

        return $this->interpret($response);
    }

    /**
     * Ambil pesan masuk (polling pendek). Mengembalikan null kalau gagal.
     *
     * @return list<array<string, mixed>>|null
     */
    public function getUpdates(int $offset): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($this->url('getUpdates'), [
                'offset' => $offset,
                'timeout' => 0,
                'allowed_updates' => json_encode(['message']),
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || ! $response->json('ok')) {
            Log::warning('Telegram getUpdates gagal', ['status' => $response->status(), 'detail' => $response->json('description')]);

            return null;
        }

        return $response->json('result') ?? [];
    }

    /** Escape untuk parse_mode HTML Telegram: hanya &, <, dan > yang perlu. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function interpret(Response $response): TelegramResult
    {
        if ($response->successful() && $response->json('ok')) {
            return new TelegramResult(TelegramOutcome::Sent);
        }

        $description = (string) $response->json('description', "HTTP {$response->status()}");

        // 401 berarti token bot salah atau dicabut: semua peringatan Telegram akan gagal sampai diperbaiki.
        if ($response->status() === 401) {
            Log::critical('Token bot Telegram ditolak (401). Periksa TELEGRAM_BOT_TOKEN di .env.', ['detail' => $description]);
        }

        return match (true) {
            // 403: pengguna memblokir bot. 400 "chat not found": chat sudah tidak ada.
            $response->status() === 403, $response->status() === 400 && str_contains($description, 'chat not found')
                => new TelegramResult(TelegramOutcome::Blocked, error: $description),
            $response->status() === 429
                => new TelegramResult(TelegramOutcome::RetryLater, (int) $response->json('parameters.retry_after', 5), $description),
            $response->serverError()
                => new TelegramResult(TelegramOutcome::RetryLater, error: $description),
            default => new TelegramResult(TelegramOutcome::Failed, error: $description),
        };
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }
}
