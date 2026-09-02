<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_promotions', function (Blueprint $table) {
            $table->foreignId('bundle_product_id')
                ->nullable()
                ->after('product_id')
                ->constrained('products', 'product_id')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_promotions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bundle_product_id');
        });
    }
};
