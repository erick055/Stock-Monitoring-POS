<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('part_compatibilities');
        Schema::dropIfExists('motorcycles');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'dimensions',
                'specifications',
                'required_features',
                'supported_vehicles',
                'fitment_source',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('dimensions')->nullable()->after('category');
            $table->json('specifications')->nullable()->after('dimensions');
            $table->json('required_features')->nullable()->after('specifications');
            $table->json('supported_vehicles')->nullable()->after('required_features');
            $table->string('fitment_source')->nullable()->after('supported_vehicles');
        });

        Schema::create('motorcycles', function (Blueprint $table) {
            $table->id('motorcycle_id');
            $table->string('brand', 100);
            $table->string('model', 100);
            $table->unsignedSmallInteger('year');
            $table->string('engine', 100);
            $table->string('variant', 100)->nullable();
            $table->json('specifications')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();
            $table->index(['brand', 'model', 'year']);
        });

        Schema::create('part_compatibilities', function (Blueprint $table) {
            $table->id('compatibility_id');
            $table->foreignId('product_id')->constrained('products', 'product_id')->cascadeOnDelete();
            $table->foreignId('motorcycle_id')->constrained('motorcycles', 'motorcycle_id')->cascadeOnDelete();
            $table->string('compatibility_status', 30)->default('unverified');
            $table->text('fitment_notes')->nullable();
            $table->json('reasons')->nullable();
            $table->json('conditions')->nullable();
            $table->string('source_reference')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'motorcycle_id']);
            $table->index('compatibility_status');
        });
    }
};
