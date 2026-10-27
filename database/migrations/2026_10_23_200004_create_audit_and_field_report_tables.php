<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak audit logistik: tiap baris menyimpan hash baris sebelumnya,
        // sehingga perubahan diam-diam pada data lama bisa terdeteksi.
        Schema::create('logistics_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->uuid('entity_id');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->geometry('geo_point', subtype: 'point', srid: 4326)->nullable();
            $table->decimal('geo_accuracy_m', 8, 2)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            // Presisi mikrodetik: waktu ikut di-hash, jadi tidak boleh terpotong saat disimpan.
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->index(['entity_type', 'entity_id']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION forbid_audit_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'logistics_audit_logs bersifat append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_audit_append_only
BEFORE UPDATE OR DELETE ON logistics_audit_logs
FOR EACH ROW EXECUTE FUNCTION forbid_audit_mutation();
SQL);

        // Laporan relawan, termasuk yang dibuat offline. ID dibuat di perangkat (UUID),
        // jadi sinkronisasi ulang tidak menggandakan data.
        Schema::create('field_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('camp_id')->constrained('evacuation_camps')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->jsonb('payload');
            $table->geometry('geo_point', subtype: 'point', srid: 4326)->nullable();
            $table->decimal('geo_accuracy_m', 8, 2)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();

            $table->index(['camp_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_reports');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_append_only ON logistics_audit_logs');
        Schema::dropIfExists('logistics_audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS forbid_audit_mutation()');
    }
};
