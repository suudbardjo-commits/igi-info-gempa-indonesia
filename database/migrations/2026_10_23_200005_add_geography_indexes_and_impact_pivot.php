<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kueri radius memakai coordinates::geography. Indeks GIST pada kolom geometry
        // tidak dipakai untuk ekspresi itu, jadi dibuat indeks fungsional yang cocok.
        foreach (['disaster_events', 'evacuation_camps', 'relief_warehouses'] as $table) {
            DB::statement("CREATE INDEX idx_{$table}_geog_gist ON {$table} USING GIST ((coordinates::geography))");
        }

        // Hasil perhitungan dampak: posko mana yang masuk radius suatu kejadian.
        Schema::create('disaster_event_camp', function (Blueprint $table) {
            $table->foreignUuid('disaster_event_id')->constrained('disaster_events')->cascadeOnDelete();
            $table->foreignUuid('camp_id')->constrained('evacuation_camps')->cascadeOnDelete();
            $table->decimal('distance_km', 8, 2);
            $table->timestamp('assessed_at')->useCurrent();

            $table->primary(['disaster_event_id', 'camp_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disaster_event_camp');

        foreach (['disaster_events', 'evacuation_camps', 'relief_warehouses'] as $table) {
            DB::statement("DROP INDEX IF EXISTS idx_{$table}_geog_gist");
        }
    }
};
