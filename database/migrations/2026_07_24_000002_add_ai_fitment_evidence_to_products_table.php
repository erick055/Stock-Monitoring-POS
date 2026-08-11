<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('manufacturer')->nullable()->after('name');
            $table->string('manufacturer_part_number')->nullable()->index()->after('manufacturer');
            $table->json('supported_vehicles')->nullable()->after('required_features');
            $table->string('fitment_source')->nullable()->after('supported_vehicles');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['manufacturer', 'manufacturer_part_number', 'supported_vehicles', 'fitment_source']);
        });
    }
};
