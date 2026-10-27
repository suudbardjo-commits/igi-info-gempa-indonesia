<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AreaController extends Controller
{
    private const LEVELS = [1 => 'provinsi', 2 => 'kabupaten/kota', 3 => 'kecamatan', 4 => 'desa/kelurahan'];

    /** GET /api/v1/areas?parent=31.71  Tanpa parent: provinsi. Selain itu anak langsung dari parent. */
    public function __invoke(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), ['parent' => ['nullable', 'regex:/^\d{2}(\.\d{2}(\.\d{2})?)?$/']]);
        if ($v->fails()) {
            return response()->json(['status' => 'error', 'error' => ['code' => 'invalid_request', 'message' => 'Kode induk tidak sah (contoh: 31, 31.71, atau 31.71.03).']], 422);
        }

        $version = DB::table('admin_area_versions')->latest('id')->first();
        if (! $version) {
            return response()->json(['status' => 'error', 'error' => ['code' => 'catalog_missing', 'message' => 'Katalog wilayah belum diimpor.']], 503);
        }

        $parent = $request->query('parent');
        $rows = DB::table('admin_areas')
            ->when($parent, fn ($q) => $q->where('parent_code', $parent), fn ($q) => $q->where('level', 1))
            ->orderBy('name')->get(['code', 'name', 'level']);

        return response()->json([
            'status' => 'ok',
            'parent' => $parent,
            'level_name' => self::LEVELS[($parent ? count(explode('.', $parent)) : 0) + 1],
            // Anak dari kecamatan adalah desa/kelurahan: kodenya (adm4) dipakai untuk prakiraan BMKG.
            'selectable_for_bmkg' => $parent && count(explode('.', $parent)) === 3,
            'catalog' => [
                'decree' => $version->decree, 'source' => $version->source, 'license' => $version->license,
                'source_url' => $version->source_url, 'imported_at' => $version->imported_at, 'counts' => json_decode($version->counts),
            ],
            'areas' => $rows,
        ]);
    }
}
