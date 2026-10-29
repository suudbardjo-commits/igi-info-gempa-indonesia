<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laporan warga "Did You Feel It": tanpa identitas, koordinat dibulatkan 2 desimal (~1 km) sebelum disimpan.
        Schema::create('felt_reports', function (Blueprint $table) {
            $table->id();
            $table->string('level', 8); // none | light | strong
            $table->decimal('latitude', 5, 2);
            $table->decimal('longitude', 5, 2);
            $table->string('quake_key', 80)->nullable();
            $table->timestampTz('felt_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('felt_reports');
    }
};
