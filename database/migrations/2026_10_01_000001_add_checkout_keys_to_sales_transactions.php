<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->uuid('checkout_key')->nullable();
            $table->string('checkout_fingerprint', 64)->nullable();
            $table->unique(['staff_id', 'checkout_key']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->dropUnique(['staff_id', 'checkout_key']);
            $table->dropColumn(['checkout_key', 'checkout_fingerprint']);
        });
    }
};
