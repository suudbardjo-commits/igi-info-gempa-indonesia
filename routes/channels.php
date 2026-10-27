<?php

use App\Models\EvacuationCamp;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// 'public-disasters' adalah channel publik, tidak perlu otorisasi.

// Peringatan untuk satu posko: koordinator dan relawan posko itu, atau petugas koordinasi yang memantau semua posko.
Broadcast::channel('camps.{campId}', function (User $user, string $campId) {
    if ($user->can('demand.view-all')) {
        return true;
    }

    // ID yang bukan UUID tidak boleh sampai ke kueri (kolom bertipe uuid akan melempar galat SQL).
    return Str::isUuid($campId)
        && EvacuationCamp::whereKey($campId)->reportableBy($user)->exists();
});

// Ringkasan dampak untuk konsol logistik (pengelola gudang dan petugas koordinasi).
Broadcast::channel('logistics', fn (User $user) => Gate::forUser($user)->allows('access-logistics'));
