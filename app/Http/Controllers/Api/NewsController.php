<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalNews\OfficialNewsFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NewsController extends Controller
{
    /** GET /api/v1/news?limit=  Kabar dari lembaga resmi (judul, ringkasan, tautan ke sumber). Bukan artikel tulisan IGI. */
    public function __invoke(Request $request, OfficialNewsFeed $feed): JsonResponse
    {
        $v = Validator::make($request->query(), ['limit' => ['nullable', 'integer', 'min:1', 'max:40']]);
        if ($v->fails()) {
            return response()->json(['status' => 'error', 'error' => ['code' => 'invalid_request', 'message' => $v->errors()->first()]], 422);
        }

        return response()->json(['status' => 'ok'] + $feed->forPage((int) $request->query('limit', 20)));
    }
}
