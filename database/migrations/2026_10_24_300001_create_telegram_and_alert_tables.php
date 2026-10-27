<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tautan akun ke chat Telegram. Kode tautan disimpan sebagai hash dan hanya berlaku sekali, singkat.
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id')->nullable()->unique();
            $table->char('code_hash', 64)->nullable()->index();
            $table->timestampTz('code_expires_at')->nullable();
            $table->timestampTz('linked_at')->nullable();
            $table->timestamps();
        });

        // Catatan pengiriman peringatan. Unik per (kejadian, pengguna, kanal) sehingga satu orang tidak
        // pernah menerima peringatan ganda untuk gempa yang sama, walau penilaian dampak dijalankan ulang.
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('disaster_event_id')->constrained('disaster_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('reason', 30);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['disaster_event_id', 'user_id', 'channel']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('telegram_links');
    }
};
