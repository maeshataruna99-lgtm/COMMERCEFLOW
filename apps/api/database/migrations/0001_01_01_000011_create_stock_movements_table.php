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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['PURCHASE', 'SALE', 'RESERVATION', 'RELEASE', 'ADJUSTMENT', 'RETURN']);
            $table->integer('quantity');
            $table->integer('before_physical');
            $table->integer('after_physical');
            $table->integer('before_reserved');
            $table->integer('after_reserved');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('stock_reservations')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_at_most_one_reference_check CHECK (CASE WHEN order_id IS NULL THEN 0 ELSE 1 END + CASE WHEN reservation_id IS NULL THEN 0 ELSE 1 END <= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
