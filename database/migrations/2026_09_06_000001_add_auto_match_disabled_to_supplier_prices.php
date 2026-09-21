<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_prices', function (Blueprint $table) {
            $table->boolean('auto_match_disabled')->default(false)->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_prices', function (Blueprint $table) {
            $table->dropColumn('auto_match_disabled');
        });
    }
};
