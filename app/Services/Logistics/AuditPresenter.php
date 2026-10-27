<?php

namespace App\Services\Logistics;

use Carbon\CarbonImmutable;

/** Mengubah baris audit mentah menjadi bentuk yang bisa dibaca orang. */
class AuditPresenter
{
    public const LABELS = [
        'INVENTORY_IN' => 'Stok masuk gudang',
        'STOCK_RESERVED' => 'Stok direservasi',
        'STOCK_RESERVATION_RELEASED' => 'Reservasi dilepas',
        'STOCK_DISPATCHED_OUT' => 'Stok keluar gudang',
        'STOCK_RECEIVED_AT_CAMP' => 'Stok diterima posko',
        'DISPATCH_CREATED' => 'Pengiriman dibuat',
        'DISPATCH_SENT' => 'Pengiriman berangkat',
        'DISPATCH_IN_TRANSIT' => 'Pengiriman dalam perjalanan',
        'DISPATCH_RECEIVED' => 'Pengiriman diterima',
        'DISPATCH_CANCELLED' => 'Pengiriman dibatalkan',
    ];

    /** @return array<string, mixed> */
    public static function row(object $r): array
    {
        $new = $r->new_values ? json_decode($r->new_values, true) : [];

        return [
            'id' => $r->id,
            'action' => $r->action,
            'label' => self::LABELS[$r->action] ?? $r->action,
            'at' => CarbonImmutable::parse($r->created_at)->utc()->toIso8601String(),
            'user' => $r->user_name ? ['name' => $r->user_name, 'email' => $r->user_email] : null,
            'ip' => $r->ip_address,
            'location' => $r->lat === null ? null : [
                'lat' => round((float) $r->lat, 5),
                'lng' => round((float) $r->lng, 5),
                'accuracy_m' => $r->geo_accuracy_m === null ? null : (float) $r->geo_accuracy_m,
            ],
            'summary' => self::summary($r->action, $new),
        ];
    }

    /** @param array<string, mixed> $n */
    private static function summary(string $action, array $n): string
    {
        // Baris lama dengan kunci yang tak terduga tidak boleh membuat seluruh daftar gagal dimuat.
        try {
            return self::describe($action, $n);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string, mixed> $n */
    private static function describe(string $action, array $n): string
    {
        return match ($action) {
            'INVENTORY_IN' => "{$n['item']} batch {$n['batch']} di {$n['warehouse']}: +{$n['qty_in']} (tersedia {$n['qty_available']})",
            'STOCK_RESERVED', 'STOCK_RESERVATION_RELEASED' => "batch {$n['batch']}: {$n['qty']} (tersedia {$n['qty_available']}, direservasi {$n['qty_reserved']})",
            'STOCK_DISPATCHED_OUT' => "batch {$n['batch']}: -{$n['qty']} (tersedia {$n['qty_available']})",
            'STOCK_RECEIVED_AT_CAMP' => "{$n['item']} di {$n['camp']}: +{$n['qty_in']} untuk {$n['dispatch']} (di posko {$n['qty_on_hand']})",
            'DISPATCH_CREATED' => "{$n['code']}: {$n['warehouse']} ke {$n['camp']}, {$n['quantity']}",
            'DISPATCH_SENT' => 'Berangkat'.(! empty($n['driver']) ? ", sopir {$n['driver']}" : '').(! empty($n['vehicle']) ? ", {$n['vehicle']}" : ''),
            'DISPATCH_CANCELLED' => 'Alasan: '.($n['reason'] ?? '-'),
            'DISPATCH_RECEIVED' => self::received($n),
            default => '',
        };
    }

    /** @param array<string, mixed> $n */
    private static function received(array $n): string
    {
        $parts = ['Jarak ke posko '.number_format((float) ($n['distance_to_camp_m'] ?? 0), 0, ',', '.').' m'];

        if (! empty($n['geofence_overridden'])) {
            $parts[] = 'geofence dilewati: '.($n['geofence_override_reason'] ?? '-');
        }

        if (! empty($n['shortfalls'])) {
            $parts[] = count($n['shortfalls']).' item kurang dari yang dikirim';
        }

        return implode('; ', $parts);
    }
}
