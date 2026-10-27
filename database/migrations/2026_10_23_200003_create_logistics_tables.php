<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name');
            $table->string('unit', 50);
            $table->boolean('is_perishable')->default(false);
            $table->timestamps();
        });

        Schema::create('warehouse_inventories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('relief_warehouses')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('batch_number', 100);
            $table->decimal('quantity_available', 12, 2)->default(0);
            $table->decimal('quantity_reserved', 12, 2)->default(0);
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id', 'batch_number'], 'uq_warehouse_item_batch');
            $table->index(['item_id', 'expiry_date']);
        });

        Schema::create('camp_inventories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('camp_id')->constrained('evacuation_camps')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['camp_id', 'item_id']);
        });

        Schema::create('camp_demands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('camp_id')->constrained('evacuation_camps')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_requested', 12, 2);
            $table->decimal('quantity_fulfilled', 12, 2)->default(0);
            $table->string('urgency_level', 20)->default('high');
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['camp_id', 'status']);
        });

        Schema::create('relief_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('dispatch_code', 100)->unique();
            $table->foreignUuid('source_warehouse_id')->constrained('relief_warehouses')->restrictOnDelete();
            $table->foreignUuid('destination_camp_id')->constrained('evacuation_camps')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('driver_name')->nullable();
            $table->string('vehicle_plate_number', 50)->nullable();
            $table->timestampTz('departure_time')->nullable();
            $table->timestampTz('arrival_time')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('receiver_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('relief_dispatch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispatch_id')->constrained('relief_dispatches')->cascadeOnDelete();
            $table->foreignUuid('inventory_id')->constrained('warehouse_inventories')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_dispatched', 12, 2);
            $table->decimal('quantity_received', 12, 2)->nullable();
            $table->timestamps();
        });

        // Ledger stok: hanya bertambah. Saldo di tabel inventori adalah turunan dari baris-baris ini.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30)->index();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('warehouse_inventory_id')->nullable()->constrained('warehouse_inventories')->restrictOnDelete();
            $table->foreignUuid('camp_id')->nullable()->constrained('evacuation_camps')->restrictOnDelete();
            $table->foreignUuid('dispatch_id')->nullable()->constrained('relief_dispatches')->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['item_id', 'created_at']);
        });

        $checks = [
            'evacuation_camps' => ['current_occupants >= 0'],
            'warehouse_inventories' => ['quantity_available >= 0', 'quantity_reserved >= 0'],
            'camp_inventories' => ['quantity_on_hand >= 0'],
            'camp_demands' => ['quantity_requested > 0', 'quantity_fulfilled >= 0'],
            'relief_dispatch_items' => ['quantity_dispatched > 0', 'quantity_received >= 0'],
        ];

        foreach ($checks as $table => $rules) {
            foreach ($rules as $i => $rule) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$table}_{$i} CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'stock_movements', 'relief_dispatch_items', 'relief_dispatches', 'camp_demands',
            'camp_inventories', 'warehouse_inventories', 'items', 'item_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
