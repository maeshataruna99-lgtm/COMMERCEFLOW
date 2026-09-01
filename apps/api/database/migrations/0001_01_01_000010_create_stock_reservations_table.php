<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->enum('state', ['ACTIVE', 'EXPIRED', 'RELEASED', 'CONSUMED'])->default('ACTIVE');
            $table->timestamp('reserved_until')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_check CHECK (quantity > 0)');

        DB::statement('CREATE UNIQUE INDEX stock_reservations_order_product_active ON stock_reservations (order_id, product_id) WHERE state = \'ACTIVE\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
