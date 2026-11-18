<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_prices', function (Blueprint $table) {
            $table->timestamp('stock_applied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_prices', fn (Blueprint $table) => $table->dropColumn('stock_applied_at'));
    }
};
