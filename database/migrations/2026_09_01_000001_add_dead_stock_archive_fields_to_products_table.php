<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('dead_stock_archived_at')->nullable()->index();
            $table->foreignId('dead_stock_archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dead_stock_archive_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['dead_stock_archived_by']);
            $table->dropColumn([
                'dead_stock_archived_at',
                'dead_stock_archived_by',
                'dead_stock_archive_note',
            ]);
        });
    }
};
