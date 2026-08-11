<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_orders', function (Blueprint $table) {
            $table->id('held_order_id');
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_sale_id')->nullable()->constrained('sales_transactions', 'sale_id')->nullOnDelete();
            $table->string('status', 30)->default('held');
            $table->timestamp('held_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'held_at']);
        });

        Schema::create('held_order_items', function (Blueprint $table) {
            $table->id('held_order_item_id');
            $table->foreignId('held_order_id')->constrained('held_orders', 'held_order_id')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_order_items');
        Schema::dropIfExists('held_orders');
    }
};
