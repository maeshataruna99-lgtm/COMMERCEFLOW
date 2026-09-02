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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->enum('status', [
                'CREATED',
                'RESERVED',
                'PAID',
                'PACKED',
                'SHIPPED',
                'COMPLETED',
                'CANCELLED',
                'EXPIRED',
                'REFUNDED',
            ])->default('CREATED');
            $table->bigInteger('total_cents')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_cents_check CHECK (total_cents >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
