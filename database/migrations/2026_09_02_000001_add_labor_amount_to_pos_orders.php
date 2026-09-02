<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->decimal('labor_amount', 12, 2)->default(0)->after('tax_amount');
        });

        Schema::table('held_orders', function (Blueprint $table) {
            $table->decimal('labor_amount', 12, 2)->default(0)->after('staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('held_orders', function (Blueprint $table) {
            $table->dropColumn('labor_amount');
        });

        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->dropColumn('labor_amount');
        });
    }
};
