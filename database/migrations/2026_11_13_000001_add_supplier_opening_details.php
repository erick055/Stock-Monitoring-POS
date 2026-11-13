<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_import_rows', 'supplier_prices'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('manufacturer_part_number', 150)->nullable();
                $table->unsignedInteger('shop_received_quantity')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['supplier_import_rows', 'supplier_prices'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['manufacturer_part_number', 'shop_received_quantity']));
        }
    }
};
