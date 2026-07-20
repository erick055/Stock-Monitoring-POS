<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('dimensions')->nullable()->after('category');
            $table->json('specifications')->nullable()->after('dimensions');
            $table->json('required_features')->nullable()->after('specifications');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['description', 'dimensions', 'specifications', 'required_features']);
        });
    }
};
