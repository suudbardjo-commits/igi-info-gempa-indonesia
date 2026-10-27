<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disaster_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id')->unique();
            $table->string('source', 20)->index();
            $table->string('type', 20)->default('earthquake')->index();
            $table->decimal('magnitude', 4, 2)->nullable();
            $table->decimal('depth_km', 6, 2)->nullable();
            $table->string('location_name');
            $table->text('tsunami_potential')->nullable();
            $table->geometry('coordinates', subtype: 'point', srid: 4326);
            $table->unsignedInteger('impact_radius_km')->default(25);
            // Sumber otomatis (BMKG/USGS) diset true oleh ingesti; input manual menunggu verifikasi operator BPBD.
            $table->boolean('is_verified')->default(false);
            // Kejadian yang sama dari sumber lain menunjuk ke kejadian utama (dedup lintas sumber).
            $table->uuid('canonical_event_id')->nullable()->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestamps();

            $table->spatialIndex('coordinates');
        });

        // Kunci asing ke tabel sendiri baru bisa dibuat setelah primary key ada.
        Schema::table('disaster_events', function (Blueprint $table) {
            $table->foreign('canonical_event_id')->references('id')->on('disaster_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disaster_events');
    }
};
