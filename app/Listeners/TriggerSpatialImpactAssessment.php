<?php

namespace App\Listeners;

use App\Events\EarthquakeDetectedEvent;
use App\Jobs\CalculateDisasterImpactJob;

class TriggerSpatialImpactAssessment
{
    public function handle(EarthquakeDetectedEvent $event): void
    {
        CalculateDisasterImpactJob::dispatch($event->disaster->id);
    }
}
