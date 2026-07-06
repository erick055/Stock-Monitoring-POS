<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledgers', function (Blueprint $table) {
            $table->id('ledger_id');
            $table->foreignId('product_id')->constrained('products', 'product_id')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('qty_in')->default(0);
            $table->unsignedInteger('qty_out')->default(0);
            $table->string('reason_code', 50);
            $table->text('logs')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index('reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledgers');
    }
};
