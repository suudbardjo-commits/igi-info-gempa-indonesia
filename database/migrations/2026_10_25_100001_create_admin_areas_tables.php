<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_areas', function (Blueprint $table) {
            $table->string('code', 13)->primary();
            $table->string('name', 120);
            $table->unsignedTinyInteger('level'); // 1 provinsi, 2 kab/kota, 3 kecamatan, 4 desa/kelurahan
            $table->string('parent_code', 13)->nullable()->index();
            $table->index(['level', 'name']);
        });

        Schema::create('admin_area_versions', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('decree');
            $table->string('license');
            $table->string('source_url');
            $table->string('source_commit', 64)->nullable();
            $table->string('file_sha256', 64);
            $table->json('counts');
            $table->timestamp('imported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_area_versions');
        Schema::dropIfExists('admin_areas');
    }
};
