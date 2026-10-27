<?php

namespace App\Listeners;

use App\Events\CampDemandCreatedEvent;
use App\Jobs\AutoMatchLogisticsJob;

class QueueAllocationSuggestion
{
    public function handle(CampDemandCreatedEvent $event): void
    {
        AutoMatchLogisticsJob::dispatch($event->demand->id);
    }
}
