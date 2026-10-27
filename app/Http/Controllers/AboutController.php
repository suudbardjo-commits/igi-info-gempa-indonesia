<?php

namespace App\Http\Controllers;

use App\Services\SourceStatus;
use Inertia\Inertia;
use Inertia\Response;

class AboutController extends Controller
{
    public function __invoke(SourceStatus $status): Response
    {
        return Inertia::render('Tentang', ['sources' => $status->report()]);
    }
}
