<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_items', function (Blueprint $table) {
            $table->id('sale_item_id');
            $table->foreignId('sale_id')->constrained('sales_transactions', 'sale_id')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_sale_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_items');
    }
};
