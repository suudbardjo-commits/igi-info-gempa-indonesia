<?php

namespace App\Jobs;

use App\Models\DisasterEvent;
use App\Services\Alerts\AlertDispatcher;
use App\Services\GeoSpatial\ImpactAssessmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CalculateDisasterImpactJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $disasterId)
    {
        // Antrean khusus yang diproses lebih dulu: dari sini peringatan ke posko dan operator berangkat.
        $this->onQueue('alerts');
    }

    public function handle(ImpactAssessmentService $service, AlertDispatcher $alerts): void
    {
        $disaster = DisasterEvent::find($this->disasterId);

        if (! $disaster) {
            return;
        }

        $camps = $service->assess($disaster);

        Log::info('Penilaian dampak selesai', [
            'disaster_id' => $disaster->id,
            'location' => $disaster->location_name,
            'camps_affected' => $camps->count(),
        ]);

        // Hanya gempa segar dan bukan duplikat yang memicu peringatan (diperiksa di dalam dispatcher).
        $alerts->dispatch($disaster, $camps);
    }
}
