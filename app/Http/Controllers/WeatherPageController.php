<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Halaman cuaca publik. Datanya diambil browser dari /api/v1/weather dan sekitarnya; tidak ada data cuaca pada muatan awal. */
class WeatherPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Cuaca');
    }
}
