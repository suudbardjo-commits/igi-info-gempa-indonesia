<?php

namespace App\Services\Sync;

use App\Enums\DemandUrgency;
use App\Events\CampConditionReportedEvent;
use App\Exceptions\DispatchStateException;
use App\Exceptions\GeofenceViolationException;
use App\Exceptions\IdConflictException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\SyncRejectedException;
use App\Models\EvacuationCamp;
use App\Models\FieldReport;
use App\Models\ReliefDispatch;
use App\Models\SyncOperation;
use App\Models\User;
use App\Services\Logistics\DemandCreator;
use App\Services\Logistics\DispatchService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Memproses satu operasi yang dikumpulkan perangkat lapangan saat offline.
 *
 * Jaminannya:
 *  - Idempoten: ID operasi (UUID dari perangkat) diklaim di tabel sync_operations sebelum diproses. Mengirim ulang
 *    operasi yang sama tidak menjalankannya lagi; hasil aslinya dikembalikan dengan status "duplicate".
 *  - Satu operasi, satu transaksi: kalau gagal di tengah, tidak ada efek samping dan klaimnya ikut batal (status
 *    "retry", aman dikirim ulang). Penolakan yang bersifat tetap (tidak berhak, di luar geofence, input salah)
 *    dicatat sebagai "rejected" supaya perangkat berhenti mencoba.
 *  - Konflik jumlah pengungsi: laporan terbaru menurut jam perangkat yang berlaku (last-write-wins, atomik di database).
 *    Laporan yang lebih tua tetap disimpan sebagai riwayat tetapi tidak menimpa angka yang lebih baru.
 */
class SyncProcessor
{
    public const TYPES = ['occupancy_report', 'condition_report', 'demand', 'dispatch_receipt'];

    /** Jam perangkat yang maju lebih dari ini dianggap salah dan dipotong ke waktu server. */
    private const MAX_FUTURE_SKEW_MINUTES = 10;

    /** Operasi yang lebih tua dari ini tidak lagi dianggap relevan. */
    private const MAX_AGE_DAYS = 14;

    public function __construct(private DemandCreator $demands, private DispatchService $dispatches) {}

    /**
     * @param  array{id: string, type: string, recorded_at: string, payload: array<string, mixed>}  $op
     * @return array{id: string, status: string, message?: string}&array<string, mixed>
     */
    public function process(User $user, array $op): array
    {
        $id = $op['id'];

        try {
            $recordedAt = $this->recordedAt($op['recorded_at']);

            return DB::transaction(function () use ($user, $op, $id, $recordedAt) {
                $claimed = DB::table('sync_operations')->insertOrIgnore([
                    'id' => $id, 'user_id' => $user->id, 'type' => $op['type'], 'status' => 'processing',
                    // String lengkap dengan zona waktu: binding objek Carbon dibulatkan dan kehilangan zona.
                    'recorded_at' => $recordedAt->format('Y-m-d H:i:sP'), 'received_at' => now(),
                ]);

                if (! $claimed) {
                    return $this->replay($user, $id);
                }

                try {
                    // Savepoint sendiri: bila handler gagal, efek sampingnya dibatalkan tetapi klaim tetap tercatat.
                    $outcome = DB::transaction(fn () => $this->handle($user, $op['type'], $id, $recordedAt, $op['payload']));
                } catch (Throwable $e) {
                    $outcome = $this->rejection($e);
                }

                DB::table('sync_operations')->where('id', $id)->update([
                    'status' => $outcome['status'], 'result' => json_encode($outcome),
                ]);

                return ['id' => $id] + $outcome;
            });
        } catch (SyncRejectedException $e) {
            return ['id' => $id, 'status' => 'rejected', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            // Kegagalan tak terduga (database, jaringan): klaim ikut dibatalkan, perangkat boleh mengirim ulang.
            report($e);
            Log::warning('Operasi sinkronisasi gagal diproses', ['id' => $id, 'type' => $op['type'], 'error' => class_basename($e)]);

            return ['id' => $id, 'status' => 'retry', 'message' => 'Server belum bisa memproses. Akan dicoba lagi otomatis.'];
        }
    }

    /** @return array<string, mixed> */
    private function replay(User $user, string $id): array
    {
        $existing = SyncOperation::find($id);

        // ID milik pengguna lain: jangan bocorkan apa pun tentang operasi itu.
        if (! $existing || $existing->user_id !== $user->id) {
            return ['id' => $id, 'status' => 'rejected', 'message' => 'ID operasi sudah dipakai.'];
        }

        if ($existing->status === 'processing') {
            return ['id' => $id, 'status' => 'retry', 'message' => 'Operasi ini sedang diproses. Coba lagi sebentar.'];
        }

        return [
            'id' => $id,
            'status' => 'duplicate',
            'original_status' => $existing->status,
            'message' => $existing->result['message'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function handle(User $user, string $type, string $opId, CarbonImmutable $recordedAt, array $payload): array
    {
        return match ($type) {
            'occupancy_report' => $this->occupancy($user, $opId, $recordedAt, $payload),
            'condition_report' => $this->condition($user, $opId, $recordedAt, $payload),
            'demand' => $this->demand($user, $opId, $payload),
            'dispatch_receipt' => $this->dispatchReceipt($user, $recordedAt, $payload),
        };
    }

    /** @return array<string, mixed> */
    private function occupancy(User $user, string $opId, CarbonImmutable $recordedAt, array $payload): array
    {
        $data = $this->validate($payload, [
            'camp_id' => ['required', 'uuid'],
            'occupants' => ['required', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
            ...$this->geoRules(),
        ]);

        $camp = $this->reportableCamp($user, $data['camp_id']);

        $this->storeReport($opId, $camp, $user, 'occupancy', ['occupants' => $data['occupants'], 'note' => $data['note'] ?? null], $recordedAt, $data);

        $ts = $recordedAt->format('Y-m-d H:i:sP');

        // Satu UPDATE bersyarat: hanya berlaku bila laporan ini lebih baru daripada yang sedang berlaku. Dua perangkat
        // yang mengirim bersamaan tidak bisa saling menimpa dengan data yang lebih tua.
        $applied = DB::update(
            "UPDATE evacuation_camps
                SET current_occupants = ?, occupants_updated_at = ?,
                    status = CASE
                        WHEN status = 'active' AND ? >= capacity_people THEN 'full'
                        WHEN status = 'full' AND ? < capacity_people THEN 'active'
                        ELSE status END,
                    updated_at = now()
              WHERE id = ? AND (occupants_updated_at IS NULL OR occupants_updated_at < ?)",
            [$data['occupants'], $ts, $data['occupants'], $data['occupants'], $camp->id, $ts],
        ) > 0;

        return $applied
            ? ['status' => 'applied', 'message' => "Jumlah pengungsi {$camp->name}: {$data['occupants']} orang."]
            : ['status' => 'applied_stale', 'message' => 'Laporan tersimpan, tetapi sudah ada laporan yang lebih baru untuk posko ini.'];
    }

    /** @return array<string, mixed> */
    private function condition(User $user, string $opId, CarbonImmutable $recordedAt, array $payload): array
    {
        $data = $this->validate($payload, [
            'camp_id' => ['required', 'uuid'],
            'severity' => ['required', Rule::in(['aman', 'waspada', 'darurat'])],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf(($payload['severity'] ?? null) === 'darurat')],
            ...$this->geoRules(),
        ]);

        $camp = $this->reportableCamp($user, $data['camp_id']);

        $this->storeReport($opId, $camp, $user, 'condition', ['severity' => $data['severity'], 'note' => $data['note'] ?? null], $recordedAt, $data);

        // Kondisi darurat yang masih baru langsung diberitahukan ke konsol; laporan lama yang baru terkirim tidak.
        if ($data['severity'] === 'darurat' && $recordedAt->gte(now()->subMinutes((int) config('disaster.alerts.max_age_minutes')))) {
            CampConditionReportedEvent::dispatch(['name' => $camp->name, 'code' => $camp->code], 'darurat', $data['note'], $recordedAt->toIso8601String());
        }

        return ['status' => 'applied', 'message' => 'Laporan kondisi tersimpan.'];
    }

    /** @return array<string, mixed> */
    private function demand(User $user, string $opId, array $payload): array
    {
        $data = $this->validate($payload, [
            'camp_id' => ['required', 'uuid'],
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'quantity_requested' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'urgency_level' => ['required', Rule::enum(DemandUrgency::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $camp = EvacuationCamp::findOrFail($data['camp_id']);

        if (! Gate::forUser($user)->allows('createForCamp', [\App\Models\CampDemand::class, $camp])) {
            throw new AuthorizationException;
        }

        // ID operasi dipakai sebagai ID permintaan: pengiriman ulang tidak membuat permintaan ganda.
        ['demand' => $demand] = $this->demands->create($user, $camp, $data, $opId);

        return ['status' => 'applied', 'message' => 'Permintaan kebutuhan diajukan.', 'demand_id' => $demand->id];
    }

    /** @return array<string, mixed> */
    private function dispatchReceipt(User $user, CarbonImmutable $recordedAt, array $payload): array
    {
        $data = $this->validate($payload, [
            'dispatch_id' => ['required', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'received' => ['nullable', 'array'],
            'received.*.dispatch_item_id' => ['required', 'uuid'],
            'received.*.quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $dispatch = ReliefDispatch::findOrFail($data['dispatch_id']);

        if (! Gate::forUser($user)->allows('receive', $dispatch)) {
            throw new AuthorizationException;
        }

        $received = collect($data['received'] ?? [])->mapWithKeys(fn ($r) => [$r['dispatch_item_id'] => $r['quantity']])->all();

        // Lokasi yang dicatat perangkat saat menerima yang dipakai untuk geofence; waktu tiba memakai jam perangkat.
        $this->dispatches->receive(
            $dispatch, $user,
            ['lat' => (float) $data['latitude'], 'lng' => (float) $data['longitude'], 'accuracy' => isset($data['accuracy']) ? (float) $data['accuracy'] : null],
            $received, $data['notes'] ?? null, null, $recordedAt,
        );

        return ['status' => 'applied', 'message' => "Pengiriman {$dispatch->dispatch_code} diterima."];
    }

    /** @param array<string, mixed> $data */
    private function storeReport(string $opId, EvacuationCamp $camp, User $user, string $type, array $payload, CarbonImmutable $recordedAt, array $data): void
    {
        $hasGeo = isset($data['latitude'], $data['longitude']);

        FieldReport::create([
            'id' => $opId,
            'camp_id' => $camp->id,
            'user_id' => $user->id,
            'type' => $type,
            'payload' => $payload,
            'geo_point' => $hasGeo ? FieldReport::makePoint((float) $data['longitude'], (float) $data['latitude']) : null,
            'geo_accuracy_m' => $data['accuracy'] ?? null,
            'recorded_at' => $recordedAt,
            'synced_at' => now(),
        ]);
    }

    private function reportableCamp(User $user, string $campId): EvacuationCamp
    {
        $camp = EvacuationCamp::findOrFail($campId);

        if (! Gate::forUser($user)->allows('report', $camp)) {
            throw new AuthorizationException;
        }

        return $camp;
    }

    /** @return array<string, list<string>> */
    private function geoRules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $payload, array $rules): array
    {
        return Validator::make($payload, $rules)->validate();
    }

    private function recordedAt(string $value): CarbonImmutable
    {
        try {
            $at = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new SyncRejectedException('Waktu pencatatan tidak valid.');
        }

        if ($at->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            throw new SyncRejectedException('Laporan terlalu lama (lebih dari '.self::MAX_AGE_DAYS.' hari) dan tidak diterima.');
        }

        // Jam perangkat yang salah (maju) dipotong ke sekarang supaya tidak mengunci pembaruan berikutnya.
        // Dipotong ke detik karena kolom waktu berpresisi detik: dua laporan di detik yang sama dianggap sama baru.
        return ($at->gt(now()->addMinutes(self::MAX_FUTURE_SKEW_MINUTES)) ? CarbonImmutable::now() : $at)->setMicroseconds(0);
    }

    /**
     * Mengubah kegagalan domain menjadi penolakan tetap dengan pesan yang bisa dibaca pengguna.
     * Kegagalan lain (bug, database) dilempar lagi supaya diperlakukan sebagai "retry".
     *
     * @return array{status: string, message: string}
     */
    private function rejection(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => ['status' => 'rejected', 'message' => collect($e->errors())->flatten()->first() ?? 'Isian tidak valid.'],
            $e instanceof AuthorizationException => ['status' => 'rejected', 'message' => 'Anda tidak berhak melakukan ini untuk posko tersebut.'],
            $e instanceof ModelNotFoundException => ['status' => 'rejected', 'message' => 'Posko atau pengiriman tidak ditemukan.'],
            $e instanceof GeofenceViolationException,
            $e instanceof DispatchStateException,
            $e instanceof InsufficientStockException,
            $e instanceof IdConflictException,
            $e instanceof SyncRejectedException,
            $e instanceof InvalidArgumentException => ['status' => 'rejected', 'message' => $e->getMessage()],
            default => throw $e,
        };
    }
}
