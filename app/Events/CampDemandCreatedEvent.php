<?php

namespace App\Events;

use App\Models\CampDemand;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Permintaan baru dari posko. Pemicu perhitungan saran alokasi stok. */
class CampDemandCreatedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CampDemand $demand) {}
}
