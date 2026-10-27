<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kutipan berita dari lembaga resmi (BMKG, BNPB): hanya judul, ringkasan singkat, tanggal, dan tautan ke sumber.
        // Isi lengkapnya tidak disalin; pembaca diarahkan ke situs penerbit.
        Schema::create('external_news', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 20);
            $table->string('external_id', 300);
            $table->string('title', 300);
            $table->string('summary', 400)->nullable();
            $table->string('url', 500);
            $table->timestampTz('published_at');
            $table->timestampTz('fetched_at');
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['source', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_news');
    }
};
