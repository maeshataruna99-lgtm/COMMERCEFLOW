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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->enum('status', [
                'PENDING',
                'PAID',
                'FAILED',
                'EXPIRED',
                'REFUNDED',
            ])->default('PENDING');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_cents_check CHECK (amount_cents >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
