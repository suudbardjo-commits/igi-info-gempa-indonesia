<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PublicStats;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function __invoke(PublicStats $stats): JsonResponse
    {
        return response()->json($stats->summary());
    }
}
