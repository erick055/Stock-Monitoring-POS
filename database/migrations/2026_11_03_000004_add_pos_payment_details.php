<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('payment_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', fn (Blueprint $table) => $table->dropColumn(['amount_tendered', 'change_due', 'payment_reference']));
    }
};
