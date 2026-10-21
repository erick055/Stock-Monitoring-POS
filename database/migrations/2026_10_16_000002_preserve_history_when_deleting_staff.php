<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_imports' => 'uploaded_by', 'product_promotions' => 'applied_by'] as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
                $blueprint->unsignedBigInteger($column)->nullable()->change();
                $blueprint->foreign($column)->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Retain nullable historical actors; rollback must not discard business records.
        foreach (['supplier_imports' => 'uploaded_by', 'product_promotions' => 'applied_by'] as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on('users')->restrictOnDelete();
            });
        }
    }
};
