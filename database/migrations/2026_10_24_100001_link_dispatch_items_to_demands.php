<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item pengiriman tahu permintaan mana yang dipenuhinya. Tanpa ini, dua pengiriman
        // bisa dialokasikan untuk kebutuhan yang sama, dan status permintaan tak bisa dihitung.
        Schema::table('relief_dispatch_items', function (Blueprint $table) {
            $table->foreignUuid('demand_id')->nullable()->after('item_id')->constrained('camp_demands')->nullOnDelete();
            $table->index('demand_id');
        });

        // Saran alokasi terakhir yang dihitung otomatis; manusia yang memutuskan membuat pengiriman.
        Schema::table('camp_demands', function (Blueprint $table) {
            $table->jsonb('suggested_plan')->nullable();
            $table->timestampTz('suggested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('camp_demands', function (Blueprint $table) {
            $table->dropColumn(['suggested_plan', 'suggested_at']);
        });

        Schema::table('relief_dispatch_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demand_id');
        });
    }
};
