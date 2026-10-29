<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Laporan warga "Did You Feel It". Tanpa akun dan tanpa identitas: yang disimpan hanya tingkat getaran, titik yang
 * dibulatkan ke ~1 km, dan waktu. Laporan tidak diverifikasi; tampilannya selalu diberi label itu.
 */
class FeltReportController extends Controller
{
    public const LEVELS = ['none', 'light', 'strong'];

    /** GET /api/v1/felt-reports?hours=6  Titik laporan terbaru untuk peta (maks 1000). */
    public function index(Request $request): JsonResponse
    {
        $hours = max(1, min(48, (int) $request->query('hours', 6)));
        $data = Cache::remember("felt-reports:$hours", 20, fn () => DB::table('felt_reports')
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderByDesc('created_at')->limit(1000)
            ->get(['level', 'latitude', 'longitude', 'created_at'])
            ->map(fn ($r) => ['level' => $r->level, 'lat' => (float) $r->latitude, 'lng' => (float) $r->longitude, 'at' => Carbon::parse($r->created_at)->toIso8601String()])
            ->all());

        return response()->json(['status' => 'ok', 'verified' => false, 'hours' => $hours, 'count' => count($data), 'reports' => $data]);
    }

    /** POST /api/v1/felt-reports  { level, lat, lng, felt_at?, quake_key? } */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'level' => ['required', 'in:'.implode(',', self::LEVELS)],
            // Hanya wilayah Indonesia (dengan sedikit batas luar) supaya peta tidak dipenuhi titik tak relevan.
            'lat' => ['required', 'numeric', 'between:-12,7'],
            'lng' => ['required', 'numeric', 'between:94,142'],
            'felt_at' => ['nullable', 'date'],
            'quake_key' => ['nullable', 'string', 'max:80'],
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 'error', 'error' => ['code' => 'invalid_request', 'message' => 'Laporan tidak sah: '.$v->errors()->first()]], 422);
        }

        $feltAt = $request->filled('felt_at') ? Carbon::parse($request->input('felt_at')) : now();
        // Waktu dari klien tidak dipercaya mentah: harus dalam 24 jam terakhir dan tidak di masa depan.
        if ($feltAt->isFuture() || $feltAt->lt(now()->subDay())) {
            $feltAt = now();
        }

        DB::table('felt_reports')->insert([
            'level' => $request->input('level'),
            'latitude' => round((float) $request->input('lat'), 2),
            'longitude' => round((float) $request->input('lng'), 2),
            'quake_key' => $request->filled('quake_key') ? substr((string) $request->input('quake_key'), 0, 80) : null,
            'felt_at' => $feltAt,
            'created_at' => now(),
        ]);
        foreach ([1, 6, 24, 48] as $h) {
            Cache::forget("felt-reports:$h");
        }

        return response()->json(['status' => 'ok', 'message' => 'Terima kasih. Laporan Anda tercatat tanpa identitas.'], 201);
    }
}
