<?php

namespace App\Services\Telegram;

use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menautkan akun ke chat Telegram lewat kode sekali pakai:
 * pengguna meminta kode di aplikasi, lalu mengirim "/start KODE" ke bot.
 */
class TelegramLinkService
{
    /** Tanpa 0/O/1/I/L supaya tidak salah baca saat diketik ulang. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    private const CODE_TTL_MINUTES = 15;

    private const MAX_WRONG_CODES_PER_HOUR = 5;

    /** @return array{code: string, expires_at: \Carbon\CarbonInterface} */
    public function issueCode(User $user): array
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $expires = now()->addMinutes(self::CODE_TTL_MINUTES);

        TelegramLink::updateOrCreate(
            ['user_id' => $user->id],
            ['code_hash' => $this->hash($code), 'code_expires_at' => $expires],
        );

        return ['code' => $code, 'expires_at' => $expires];
    }

    /**
     * Dipanggil saat bot menerima "/start KODE".
     *
     * @return array{status: 'linked'|'invalid'|'chat_in_use'|'throttled', user?: User}
     */
    public function confirm(string $rawCode, int $chatId): array
    {
        // Batasi tebakan: kode yang tertebak akan menautkan Telegram penebak ke akun orang lain.
        $key = "telegram:wrong-codes:{$chatId}";

        if ((int) Cache::get($key, 0) >= self::MAX_WRONG_CODES_PER_HOUR) {
            return ['status' => 'throttled'];
        }

        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawCode));

        $link = $code === '' ? null : TelegramLink::query()
            ->where('code_hash', $this->hash($code))
            ->where('code_expires_at', '>', now())
            ->first();

        if (! $link) {
            Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addHour());

            return ['status' => 'invalid'];
        }

        if (TelegramLink::where('chat_id', $chatId)->where('id', '!=', $link->id)->exists()) {
            return ['status' => 'chat_in_use'];
        }

        $link->update(['chat_id' => $chatId, 'linked_at' => now(), 'code_hash' => null, 'code_expires_at' => null]);
        Cache::forget($key);

        return ['status' => 'linked', 'user' => $link->user];
    }

    public function unlinkUser(User $user): void
    {
        TelegramLink::where('user_id', $user->id)->update(['chat_id' => null, 'linked_at' => null, 'code_hash' => null, 'code_expires_at' => null]);
    }

    /** @return bool true kalau ada tautan yang dicabut */
    public function unlinkChat(int $chatId): bool
    {
        return DB::table('telegram_links')->where('chat_id', $chatId)
            ->update(['chat_id' => null, 'linked_at' => null, 'updated_at' => now()]) > 0;
    }

    public function linkFor(User $user): ?TelegramLink
    {
        return TelegramLink::where('user_id', $user->id)->first();
    }

    private function hash(string $code): string
    {
        return hash('sha256', 'telegram-link|'.Str::upper($code));
    }
}
