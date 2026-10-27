<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengumuman resmi dari operator, tampil di beranda dan halaman berita sampai kedaluwarsa.
        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 140);
            $table->text('body');
            $table->string('level', 10)->default('info');
            $table->timestampTz('published_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
