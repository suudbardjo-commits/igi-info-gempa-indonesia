<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramLinkController extends Controller
{
    public function __construct(private TelegramLinkService $links, private TelegramClient $telegram) {}

    public function show(Request $request): JsonResponse
    {
        $link = $this->links->linkFor($request->user());

        return response()->json([
            'bot_configured' => $this->telegram->enabled(),
            'bot_username' => config('services.telegram.bot_username'),
            'linked' => (bool) $link?->isLinked(),
            'linked_at' => $link?->linked_at?->toIso8601String(),
        ]);
    }

    /** Kode sekali pakai yang dikirim pengguna ke bot sebagai "/start KODE". Berlaku 15 menit. */
    public function issueCode(Request $request): JsonResponse
    {
        if (! $this->telegram->enabled()) {
            return response()->json(['message' => 'Bot Telegram belum disiapkan oleh administrator.'], 503);
        }

        ['code' => $code, 'expires_at' => $expires] = $this->links->issueCode($request->user());

        return response()->json([
            'code' => $code,
            'expires_at' => $expires->toIso8601String(),
            'bot_username' => config('services.telegram.bot_username'),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->links->unlinkUser($request->user());

        return response()->json(['message' => 'Telegram diputus dari akun Anda.']);
    }
}
