<?php

namespace App\Http\Controllers;

use App\Enums\CampStatus;
use App\Models\Announcement;
use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;
use App\Services\ExternalNews\OfficialNewsFeed;
use App\Services\GeoSpatial\GeoJsonFormatter;
use App\Services\PublicStats;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(GeoJsonFormatter $geo, PublicStats $stats, OfficialNewsFeed $official): Response
    {
        $week = $geo->disasters(DisasterEvent::query()->publicFeed(hours: 168, limit: 300)->get())['features'];

        return Inertia::render('Beranda', [
            'official' => $official->forPage(4),
            'stats' => $stats->summary(),
            // Seminggu penuh untuk seismogram dan peta sebaran; "latest" hanya enam teratas untuk daftar.
            'week' => $week,
            'latest' => array_slice($week, 0, 6),
            'camps' => $geo->camps(EvacuationCamp::query()->withCoordinates()->where('status', '!=', CampStatus::Closed->value)->get())['features'],
            'announcements' => Announcement::query()->active()->limit(3)->get()->map->toPublicArray()->values(),
        ]);
    }
}
