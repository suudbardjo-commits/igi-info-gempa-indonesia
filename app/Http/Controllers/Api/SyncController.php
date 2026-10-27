<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\SyncProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyncController extends Controller
{
    /** Batas per permintaan: cukup besar untuk antrean seharian, cukup kecil supaya satu permintaan tidak menahan server lama. */
    private const MAX_OPERATIONS = 100;

    /**
     * Menerima kumpulan operasi yang dicatat perangkat saat offline. Setiap operasi diproses sendiri-sendiri (satu gagal
     * tidak membatalkan yang lain) dan hasilnya dikembalikan per operasi, sehingga perangkat tahu persis mana yang
     * boleh dihapus dari antreannya.
     *
     * Status per operasi: applied, applied_stale, duplicate (sudah pernah diterima), rejected (tetap, jangan dicoba
     * lagi), retry (sementara, coba lagi).
     */
    public function store(Request $request, SyncProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPERATIONS],
            'operations.*.id' => ['required', 'uuid'],
            'operations.*.type' => ['required', Rule::in(SyncProcessor::TYPES)],
            'operations.*.recorded_at' => ['required', 'date'],
            'operations.*.payload' => ['required', 'array'],
        ]);

        $user = $request->user();

        // Diproses berurutan menurut waktu pencatatan (jam perangkat), supaya urutan kejadian di lapangan terjaga.
        // Hasil disimpan per urutan kiriman (bukan per ID): operasi yang sama bisa muncul dua kali dalam satu kiriman,
        // dan yang pertama harus tetap dilaporkan "applied" sementara pengulangannya "duplicate".
        $results = [];

        foreach (collect($data['operations'])->map(fn ($op, $i) => $op + ['_i' => $i])->sortBy('recorded_at') as $op) {
            $results[$op['_i']] = $processor->process($user, $op);
        }

        ksort($results);

        return response()->json([
            'results' => array_values($results),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
