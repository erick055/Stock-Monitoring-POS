<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id('supplier_id');
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supplier_imports', function (Blueprint $table) {
            $table->id('supplier_import_id');
            $table->foreignId('supplier_id')->constrained('suppliers', 'supplier_id')->cascadeOnDelete();
            $table->string('source_filename');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_import_rows', function (Blueprint $table) {
            $table->id('supplier_import_row_id');
            $table->foreignId('supplier_import_id')->constrained('supplier_imports', 'supplier_import_id')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('supplier_sku');
            $table->string('product_name');
            $table->string('internal_sku')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products', 'product_id')->nullOnDelete();
            $table->string('currency', 3)->default('PHP');
            $table->decimal('unit_price', 12, 2);
            $table->integer('available_quantity')->nullable();
            $table->unsignedInteger('minimum_order_quantity')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->date('effective_date')->nullable();
            $table->json('validation_errors')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_prices', function (Blueprint $table) {
            $table->id('supplier_price_id');
            $table->foreignId('supplier_id')->constrained('suppliers', 'supplier_id')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products', 'product_id')->nullOnDelete();
            $table->string('supplier_sku');
            $table->string('product_name');
            $table->string('currency', 3)->default('PHP');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('previous_price', 12, 2)->nullable();
            $table->integer('available_quantity')->nullable();
            $table->unsignedInteger('minimum_order_quantity')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('source_type', 20)->default('spreadsheet');
            $table->string('source_filename');
            $table->timestamp('last_updated_at');
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_sku']);
            $table->index('last_updated_at');
        });

        Schema::create('supplier_price_histories', function (Blueprint $table) {
            $table->id('supplier_price_history_id');
            $table->foreignId('supplier_price_id')->constrained('supplier_prices', 'supplier_price_id')->cascadeOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->integer('available_quantity')->nullable();
            $table->foreignId('supplier_import_id')->nullable()->constrained('supplier_imports', 'supplier_import_id')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_histories');
        Schema::dropIfExists('supplier_prices');
        Schema::dropIfExists('supplier_import_rows');
        Schema::dropIfExists('supplier_imports');
        Schema::dropIfExists('suppliers');
    }
};
