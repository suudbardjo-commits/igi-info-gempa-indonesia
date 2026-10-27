<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setiap operasi dari perangkat lapangan punya ID (UUID) buatan perangkat. Baris ini diklaim sebelum operasi
        // diproses, sehingga mengirim ulang operasi yang sama (jaringan putus di tengah, tombol sinkron ditekan dua kali)
        // tidak pernah menjalankannya dua kali, dan hasil aslinya bisa dikembalikan lagi.
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('status', 20);
            $table->jsonb('result')->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampTz('received_at')->useCurrent();

            $table->index(['user_id', 'received_at']);
        });

        // Relawan ditugaskan ke posko; koordinator tetap lewat evacuation_camps.coordinator_id.
        Schema::create('camp_volunteers', function (Blueprint $table) {
            $table->foreignUuid('camp_id')->constrained('evacuation_camps')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['camp_id', 'user_id']);
        });

        // Waktu (menurut perangkat pelapor) laporan jumlah pengungsi yang sedang berlaku. Laporan yang tiba terlambat
        // tetapi lebih tua dari ini disimpan, namun tidak menimpa angka yang lebih baru.
        Schema::table('evacuation_camps', function (Blueprint $table) {
            $table->timestampTz('occupants_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('evacuation_camps', function (Blueprint $table) {
            $table->dropColumn('occupants_updated_at');
        });

        Schema::dropIfExists('camp_volunteers');
        Schema::dropIfExists('sync_operations');
    }
};
