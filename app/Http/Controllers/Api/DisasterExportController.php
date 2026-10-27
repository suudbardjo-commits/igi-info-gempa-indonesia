<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisasterEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisasterExportController extends Controller
{
    private const MAX_ROWS = 1000;

    /** GET /api/v1/disasters/export: CSV kejadian dengan filter yang sama seperti daftar di layar. */
    public function __invoke(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $v = Validator::make($request->query(), [
            'hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'min_magnitude' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'q' => ['nullable', 'string', 'max:80'],
            'south' => ['nullable', 'numeric', 'between:-90,90', 'required_with:north,west,east'],
            'north' => ['nullable', 'numeric', 'between:-90,90', 'required_with:south,west,east'],
            'west' => ['nullable', 'numeric', 'between:-180,180', 'required_with:south,north,east'],
            'east' => ['nullable', 'numeric', 'between:-180,180', 'required_with:south,north,west'],
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 'error', 'error' => ['code' => 'invalid_request', 'message' => $v->errors()->first()]], 422);
        }

        $events = DisasterEvent::query()
            ->publicFeed((int) $request->query('hours', 168), $request->filled('min_magnitude') ? (float) $request->query('min_magnitude') : null, null, 500)
            ->get();

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $box = $request->filled('south') ? array_map('floatval', $request->only(['south', 'north', 'west', 'east'])) : null;

        $rows = $events->filter(function ($e) use ($q, $box) {
            if ($q !== '' && ! str_contains(mb_strtolower($e->location_name.' '.$e->source->value), $q)) {
                return false;
            }

            return ! $box || ($e->latitude >= $box['south'] && $e->latitude <= $box['north'] && $e->longitude >= $box['west'] && $e->longitude <= $box['east']);
        })->take(self::MAX_ROWS);

        $filename = 'igi-gempa-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $request) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['# IGI Info Gempa Indonesia. Diekspor '.now()->toIso8601String().'. Sumber: BMKG dan USGS. Hingga '.self::MAX_ROWS.' baris. Bukan penetapan resmi.'], ',', '"', '');
            fputcsv($out, ['waktu_kejadian_utc', 'waktu_kejadian_wib', 'lokasi', 'magnitudo', 'kedalaman_km', 'lintang', 'bujur', 'sumber', 'potensi_tsunami'], ',', '"', '');
            foreach ($rows as $e) {
                fputcsv($out, array_map([self::class, 'safe'], [
                    $e->occurred_at->utc()->toIso8601String(),
                    $e->occurred_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    $e->location_name,
                    $e->magnitude,
                    $e->depth_km,
                    round((float) $e->latitude, 4),
                    round((float) $e->longitude, 4),
                    strtoupper($e->source->value),
                    $e->tsunami_potential,
                ]), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Sel yang diawali = + - @ tab atau CR diberi tanda petik agar tidak dijalankan sebagai rumus oleh spreadsheet. */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
