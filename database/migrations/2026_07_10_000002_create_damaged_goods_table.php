<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damaged_goods', function (Blueprint $table) {
            $table->id('damage_id');
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('damage_reason', 255);
            $table->string('replacement_status', 50)->default('pending');
            $table->string('status', 50)->default('reported');
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['product_id', 'reported_at']);
            $table->index('replacement_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damaged_goods');
    }
};
