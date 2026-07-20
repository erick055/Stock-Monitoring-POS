<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('motorcycles');
    }
};
