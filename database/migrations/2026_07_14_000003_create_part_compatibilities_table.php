<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('part_compatibilities');
    }
};
