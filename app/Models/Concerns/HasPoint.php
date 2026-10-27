<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Untuk model dengan kolom `coordinates` bertipe geometry(Point, 4326).
 */
trait HasPoint
{
    /**
     * Ekspresi titik WGS84 dari angka. Nilai di-cast ke float, jadi aman dari injeksi.
     */
    public static function makePoint(float $lng, float $lat): Expression
    {
        return DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)', $lng, $lat));
    }

    /** Menambahkan atribut `longitude` dan `latitude` dari kolom geometri. */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->addSelect("{$table}.*")
            ->selectRaw("ST_X({$table}.coordinates::geometry) AS longitude")
            ->selectRaw("ST_Y({$table}.coordinates::geometry) AS latitude");
    }

    /** Data yang berada dalam radius (km) dari titik acuan, dihitung di atas bola bumi. */
    public function scopeWithinRadius(Builder $query, float $lng, float $lat, float $radiusKm): Builder
    {
        $table = $this->getTable();

        return $query->whereRaw(
            "ST_DWithin({$table}.coordinates::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
            [$lng, $lat, $radiusKm * 1000],
        );
    }

    /** Menambahkan kolom `distance_km` dari titik acuan. */
    public function scopeWithDistanceFrom(Builder $query, float $lng, float $lat): Builder
    {
        $table = $this->getTable();

        // selectRaw saja akan menggantikan `*`; pertahankan kolom tabel kalau belum ada pilihan lain.
        if (empty($query->getQuery()->columns)) {
            $query->addSelect("{$table}.*");
        }

        return $query->selectRaw(
            "ST_Distance({$table}.coordinates::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000 AS distance_km",
            [$lng, $lat],
        );
    }
}
