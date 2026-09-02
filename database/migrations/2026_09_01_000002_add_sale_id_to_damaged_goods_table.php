<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damaged_goods', function (Blueprint $table) {
            $table->foreignId('sale_id')
                ->nullable()
                ->after('product_id')
                ->constrained('sales_transactions', 'sale_id')
                ->nullOnDelete();
            $table->index(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('damaged_goods', function (Blueprint $table) {
            $table->dropIndex(['sale_id', 'product_id']);
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
