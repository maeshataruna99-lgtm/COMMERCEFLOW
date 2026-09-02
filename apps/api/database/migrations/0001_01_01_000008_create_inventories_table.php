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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('physical_stock')->default(0);
            $table->integer('reserved_stock')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_physical_stock_check CHECK (physical_stock >= 0)');
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_reserved_stock_check CHECK (reserved_stock >= 0)');
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_reserved_le_physical_check CHECK (reserved_stock <= physical_stock)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
