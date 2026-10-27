<?php

namespace App\Services\Logistics;

use App\Models\LogisticsAuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jejak audit logistik dengan rantai hash.
 *
 * Tiap baris menyimpan hash dari isinya ditambah hash baris sebelumnya. Trigger database
 * melarang UPDATE/DELETE, dan `verify()` mendeteksi baris yang diubah lewat jalur lain
 * (misalnya trigger dimatikan atau data dipulihkan dari cadangan yang diutak-atik).
 *
 * Ini bukti anti-rusak, bukan enkripsi: isi audit tetap bisa dibaca.
 */
class AuditLogger
{
    /** Kunci advisory lock untuk menyerialkan penulisan rantai. */
    private const LOCK_KEY = 7_340_001;

    /**
     * @param  array{lat: float, lng: float, accuracy?: float|null}|null  $geo
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?array $old = null,
        ?array $new = null,
        ?User $user = null,
        ?string $ip = null,
        ?array $geo = null,
    ): LogisticsAuditLog {
        // Nilai di-hash dalam bentuk yang persis sama dengan yang tersimpan di jsonb (misalnya 0.0 menjadi 0),
        // kalau tidak verify() akan menganggap data sah sebagai rusak.
        $old = $this->storedForm($old);
        $new = $this->storedForm($new);

        return DB::transaction(function () use ($action, $entityType, $entityId, $old, $new, $user, $ip, $geo) {
            // Kunci dilepas saat transaksi terluar selesai, jadi urutan rantai tetap benar
            // walau pemanggil membungkus beberapa operasi dalam satu transaksi.
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $prev = DB::table('logistics_audit_logs')->orderByDesc('id')->value('hash');
            $at = CarbonImmutable::now('UTC');

            $fields = [
                'user_id' => $user?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => $ip,
                'geo' => $geo ? $this->geoString($geo['lng'], $geo['lat']) : null,
                'geo_accuracy_m' => isset($geo['accuracy']) ? number_format((float) $geo['accuracy'], 2, '.', '') : null,
                'created_at' => $at->format('Y-m-d\TH:i:s.u\Z'),
            ];

            $hash = $this->hash($prev, $fields);

            $id = DB::table('logistics_audit_logs')->insertGetId([
                'user_id' => $fields['user_id'],
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $old === null ? null : json_encode($old),
                'new_values' => $new === null ? null : json_encode($new),
                'ip_address' => $ip,
                'geo_point' => $geo ? DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)', $geo['lng'], $geo['lat'])) : null,
                'geo_accuracy_m' => $fields['geo_accuracy_m'],
                'prev_hash' => $prev,
                'hash' => $hash,
                'created_at' => $at->format('Y-m-d H:i:s.u').'+00',
            ]);

            return LogisticsAuditLog::findOrFail($id);
        });
    }

    /**
     * Periksa seluruh rantai. Mengembalikan id baris pertama yang rusak, atau null kalau utuh.
     */
    public function verify(): ?int
    {
        $prev = null;
        $broken = null;

        DB::table('logistics_audit_logs')
            ->selectRaw('*, ST_X(geo_point) AS lng, ST_Y(geo_point) AS lat')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$prev, &$broken) {
                foreach ($rows as $row) {
                    $fields = [
                        'user_id' => $row->user_id,
                        'action' => $row->action,
                        'entity_type' => $row->entity_type,
                        'entity_id' => $row->entity_id,
                        'old_values' => $row->old_values === null ? null : json_decode($row->old_values, true),
                        'new_values' => $row->new_values === null ? null : json_decode($row->new_values, true),
                        'ip_address' => $row->ip_address,
                        'geo' => $row->lng === null ? null : $this->geoString((float) $row->lng, (float) $row->lat),
                        'geo_accuracy_m' => $row->geo_accuracy_m === null ? null : number_format((float) $row->geo_accuracy_m, 2, '.', ''),
                        'created_at' => CarbonImmutable::parse($row->created_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    ];

                    if ($row->prev_hash !== $prev || $row->hash !== $this->hash($prev, $fields)) {
                        $broken = $row->id;

                        return false;
                    }

                    $prev = $row->hash;
                }
            });

        return $broken;
    }

    /** @param array<string, mixed>|null $values */
    private function storedForm(?array $values): ?array
    {
        return $values === null ? null : json_decode(json_encode($values), true);
    }

    /** @param array<string, mixed> $fields */
    private function hash(?string $prev, array $fields): string
    {
        return hash('sha256', ($prev ?? 'GENESIS').'|'.$this->canonical($fields));
    }

    private function geoString(float $lng, float $lat): string
    {
        return sprintf('%.6f,%.6f', $lng, $lat);
    }

    /** JSON dengan kunci terurut, supaya hasil sama walau urutan kunci berbeda (jsonb mengurutkan ulang). */
    private function canonical(mixed $value): string
    {
        return json_encode($this->sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($v) => $this->sortKeys($v), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
