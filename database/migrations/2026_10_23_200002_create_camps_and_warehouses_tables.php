<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evacuation_camps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->geometry('coordinates', subtype: 'point', srid: 4326);
            $table->unsignedInteger('capacity_people');
            $table->unsignedInteger('current_occupants')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_phone', 30);
            $table->text('address_notes')->nullable();
            $table->timestamps();

            $table->spatialIndex('coordinates');
        });

        Schema::create('relief_warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->geometry('coordinates', subtype: 'point', srid: 4326);
            $table->decimal('capacity_m3', 10, 2);
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_phone', 30);
            $table->timestamps();

            $table->spatialIndex('coordinates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relief_warehouses');
        Schema::dropIfExists('evacuation_camps');
    }
};
