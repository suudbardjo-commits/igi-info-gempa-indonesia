<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SourceStatus;
use App\Services\Weather\AlertFeed;
use App\Services\Weather\AlertMatcher;
use App\Services\Weather\BmkgForecastClient;
use App\Services\Weather\Bounds;
use App\Services\Weather\Geocoder;
use App\Services\Weather\UpstreamException;
use App\Services\Weather\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WeatherController extends Controller
{
    /** GET /api/v1/locations?q= */
    public function locations(Request $request, Geocoder $geocoder): JsonResponse
    {
        $v = Validator::make($request->query(), ['q' => ['required', 'string', 'min:2', 'max:80']]);
        if ($v->fails()) {
            return $this->invalid($v->errors()->first());
        }

        try {
            $r = $geocoder->search(preg_replace('/\s+/', ' ', trim($request->query('q'))));
        } catch (UpstreamException $e) {
            return $this->failure($e);
        }

        return response()->json([
            'status' => 'ok',
            'provider' => 'open-meteo-geocoding',
            'attribution' => 'Pencarian lokasi: GeoNames lewat Open-Meteo Geocoding. ID GeoNames bukan kode wilayah BMKG.',
            'fetched_at' => $r['fetched_at'],
            'cached' => $r['cached'],
            'results' => $r['results'],
        ]);
    }

    /** GET /api/v1/weather?lat=&lon=&name=  atau  ?adm4=31.71.03.1001 */
    public function weather(Request $request, WeatherService $weather): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_without:adm4'],
            'lon' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'adm4' => ['nullable', 'regex:'.BmkgForecastClient::ADM4],
            'name' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'in:auto,open-meteo,bmkg'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v->errors()->first());
        }

        $adm4 = $request->query('adm4');
        $provider = $request->query('provider', 'auto');

        // Kode wilayah hanya diterima bila ada di katalog resmi yang diimpor (bila katalog sudah ada).
        if ($adm4 && \Illuminate\Support\Facades\DB::table('admin_areas')->exists()
            && ! \Illuminate\Support\Facades\DB::table('admin_areas')->where('code', $adm4)->where('level', 4)->exists()) {
            return $this->invalid('Kode wilayah tidak ada di katalog Kemendagri 2025. Pilih dari daftar wilayah.');
        }

        try {
            if ($adm4 && $provider !== 'open-meteo') {
                $data = $weather->byAdm4($adm4);
            } else {
                $lat = (float) $request->query('lat');
                $lon = (float) $request->query('lon');
                if (! Bounds::contains($lat, $lon)) {
                    return $this->invalid('Koordinat di luar wilayah Indonesia yang dilayani.');
                }
                $data = $weather->byCoordinates($lat, $lon, $request->query('name'), $provider !== 'open-meteo');
            }
        } catch (UpstreamException $e) {
            return $this->failure($e);
        }

        return response()->json(['status' => 'ok'] + $data);
    }

    /** GET /api/v1/weather-alerts?lat=&lon=&province=&geometry=1 */
    public function alerts(Request $request, AlertFeed $feed, AlertMatcher $matcher): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lon'],
            'lon' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'province' => ['nullable', 'string', 'max:60'],
            'geometry' => ['nullable', 'in:0,1'],
            'state' => ['nullable', 'in:active,all'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v->errors()->first());
        }

        $r = $feed->current();
        if (! $r['ok']) {
            return response()->json(['status' => 'error', 'error' => ['code' => 'unavailable', 'message' => 'Peringatan dini BMKG belum dapat diambil. Ini bukan berarti tidak ada peringatan.'], 'alerts' => []], 503);
        }

        $lat = $request->query('lat') !== null ? (float) $request->query('lat') : null;
        $lon = $request->query('lon') !== null ? (float) $request->query('lon') : null;
        $province = $request->query('province');
        $located = $lat !== null || $province;

        $out = [];
        foreach ($r['alerts'] as $a) {
            if ($request->query('state', 'active') === 'active' && ! in_array($a['state'], ['active', 'upcoming'], true)) {
                continue;
            }
            $coverage = $located ? $matcher->match($a, $lat, $lon, $province) : null;
            if ($located && $coverage === null) {
                continue;
            }
            $out[] = $this->present($a, $coverage, $request->query('geometry') === '1');
        }

        return response()->json([
            'status' => 'ok',
            'provider' => 'bmkg-cap',
            'attribution' => config('weather.bmkg.alert_attribution'),
            'checked_at' => $r['checked_at'],
            'is_stale' => $r['stale'],
            'refresh_error' => $r['error'],
            'filtered_by_location' => (bool) $located,
            'count' => count($out),
            'alerts' => $out,
        ]);
    }

    /** GET /api/v1/sources/status */
    public function sources(SourceStatus $status): JsonResponse
    {
        return response()->json(['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'sources' => $status->forApi()]);
    }

    /** @return array<string, mixed> */
    private function present(array $a, ?string $coverage, bool $geometry): array
    {
        $out = [
            'id' => $a['identifier'],
            'title' => $a['headline'] ?: $a['event'],
            'event' => $a['event'],
            'area' => $a['area'],
            'issuer' => $a['issuer'],
            'severity' => $a['severity'],
            'urgency' => $a['urgency'],
            'certainty' => $a['certainty'],
            'sent' => $a['sent'],
            'effective' => $a['effective'],
            'expires' => $a['expires'],
            'state' => $a['state'],
            'description' => $a['description'],
            'instruction' => $a['instruction'],
            'source_url' => $a['source_url'] ?? null,
            'infographic_url' => $a['web'],
            'coverage' => $coverage,
            'polygon_count' => count($a['polygons']),
            'invalid_polygons' => $a['invalid_polygons'],
        ];
        if ($geometry && $a['polygons']) {
            $out['geometry'] = ['type' => 'MultiPolygon', 'coordinates' => array_map(fn ($r) => [$r], $a['polygons'])];
        }

        return $out;
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'error' => ['code' => 'invalid_request', 'message' => $message]], 422);
    }

    private function failure(UpstreamException $e): JsonResponse
    {
        $status = match ($e->reason) {
            'rate_limited', 'busy' => 503,
            'not_found' => 404,
            default => 502,
        };

        return response()->json(['status' => 'error', 'error' => ['code' => $e->reason, 'message' => $e->getMessage()]], $status);
    }
}
