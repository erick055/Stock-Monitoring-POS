<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dead_stock_ml_models', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->json('coefficients');
            $table->json('means');
            $table->json('scales');
            $table->unsignedInteger('training_samples');
            $table->decimal('validation_accuracy', 5, 4)->nullable();
            $table->timestamp('trained_at');
            $table->timestamps();
        });
        Schema::create('dead_stock_ml_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products', 'product_id')->cascadeOnDelete();
            $table->foreignId('model_id')->constrained('dead_stock_ml_models')->cascadeOnDelete();
            $table->decimal('stagnation_probability', 6, 5);
            $table->string('classification', 40);
            $table->json('factors');
            $table->timestamp('predicted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_stock_ml_predictions');
        Schema::dropIfExists('dead_stock_ml_models');
    }
};
