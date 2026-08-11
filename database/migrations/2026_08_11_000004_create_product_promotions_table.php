<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_promotions', function (Blueprint $table) {
            $table->id('product_promotion_id');
            $table->foreignId('product_id')->constrained('products', 'product_id')->cascadeOnDelete();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->string('action_type', 30);
            $table->decimal('discount_percent', 5, 2);
            $table->decimal('original_price', 12, 2);
            $table->decimal('promotional_price', 12, 2);
            $table->string('bundle_note', 160)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_promotions');
    }
};
