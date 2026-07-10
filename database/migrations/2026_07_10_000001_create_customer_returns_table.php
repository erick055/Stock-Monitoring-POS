<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id('return_id');
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales_transactions', 'sale_id')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 255);
            $table->string('item_condition', 50)->default('sellable');
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('status', 50)->default('approved');
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->index(['product_id', 'returned_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_returns');
    }
};
