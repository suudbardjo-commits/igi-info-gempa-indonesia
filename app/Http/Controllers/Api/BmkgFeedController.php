<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Weather\Upstream;
use App\Services\Weather\UpstreamException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Proksi tipis untuk data terbuka BMKG InaTEWS. Peramban mengambil langsung dari data.bmkg.go.id; jalur ini hanya
 * cadangan bila permintaan langsung gagal (CORS, jaringan, pemblokiran). Hanya tiga umpan yang diizinkan (tanpa URL bebas).
 */
class BmkgFeedController extends Controller
{
    public const FEEDS = ['autogempa', 'gempaterkini', 'gempadirasakan'];

    public function __invoke(string $feed, Upstream $upstream): JsonResponse
    {
        abort_unless(in_array($feed, self::FEEDS, true), 404);

        $key = "bmkg:tews:{$feed}";
        $fresh = Cache::get($key);
        if ($fresh && now()->diffInSeconds($fresh['fetched_at'], true) < 30) {
            return $this->respond($fresh, false);
        }

        try {
            $json = $upstream->get('bmkg', 'bmkg-tews', rtrim(config('disaster.bmkg.base_url'), '/')."/{$feed}.json")->json();
            if (! is_array($json) || ! isset($json['Infogempa'])) {
                $upstream->fail('bmkg-tews', 'malformed');
                throw new UpstreamException('malformed', 'Respons BMKG tidak sesuai format.');
            }
            $upstream->ok('bmkg-tews');
            $payload = ['data' => $json, 'fetched_at' => now()];
            Cache::put($key, $payload, 3600);

            return $this->respond($payload, false);
        } catch (UpstreamException $e) {
            if ($fresh) {
                return $this->respond($fresh, true);
            }

            return response()->json(['status' => 'error', 'error' => ['code' => $e->reason, 'message' => $e->getMessage()]], 502);
        }
    }

    /** @param array{data: array, fetched_at: \Carbon\CarbonInterface} $p */
    private function respond(array $p, bool $stale): JsonResponse
    {
        return response()->json(['status' => 'ok', 'source' => 'BMKG InaTEWS', 'fetched_at' => $p['fetched_at']->toIso8601String(), 'is_stale' => $stale, 'data' => $p['data']]);
    }
}
