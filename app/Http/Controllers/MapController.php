<?php

namespace App\Http\Controllers;

use App\Enums\CampStatus;
use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;
use App\Services\GeoSpatial\GeoJsonFormatter;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function __invoke(GeoJsonFormatter $geo): Response
    {
        // Data awal ikut dikirim bersama halaman supaya peta tidak kosong sesaat saat dibuka.
        return Inertia::render('Peta', [
            'events' => $geo->disasters(DisasterEvent::query()->publicFeed(hours: 168, limit: 300)->get()),
            'camps' => $geo->camps(
                EvacuationCamp::query()->withCoordinates()->where('status', '!=', CampStatus::Closed->value)->get(),
            ),
        ]);
    }
}
