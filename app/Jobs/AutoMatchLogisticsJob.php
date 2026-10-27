<?php

namespace App\Jobs;

use App\Models\CampDemand;
use App\Services\Logistics\AllocationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Menghitung saran alokasi stok untuk sebuah permintaan dan menyimpannya.
 * Hanya usulan: pengiriman tetap dibuat oleh pengelola gudang.
 */
class AutoMatchLogisticsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $demandId) {}

    public function handle(AllocationEngine $engine): void
    {
        $demand = CampDemand::find($this->demandId);

        if (! $demand) {
            return;
        }

        $demand->update([
            'suggested_plan' => $engine->plan($demand)->toArray(),
            'suggested_at' => now(),
        ]);
    }
}
