<?php

namespace App\Http\Controllers\Api;

use App\Enums\CampStatus;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCamp;
use App\Services\GeoSpatial\GeoJsonFormatter;
use Illuminate\Http\JsonResponse;

class CampController extends Controller
{
    public function index(GeoJsonFormatter $geo): JsonResponse
    {
        $camps = EvacuationCamp::query()
            ->withCoordinates()
            ->where('status', '!=', CampStatus::Closed->value)
            ->orderBy('name')
            ->get();

        return response()->json($geo->camps($camps));
    }
}
