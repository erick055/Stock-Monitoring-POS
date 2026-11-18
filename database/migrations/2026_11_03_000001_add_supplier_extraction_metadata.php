<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_imports', function (Blueprint $table) {
            $table->string('extraction_method', 20)->default('spreadsheet');
            $table->string('extraction_model', 100)->nullable();
        });
        Schema::table('supplier_import_rows', fn (Blueprint $table) => $table->string('source_reference')->nullable());
    }

    public function down(): void
    {
        Schema::table('supplier_import_rows', fn (Blueprint $table) => $table->dropColumn('source_reference'));
        Schema::table('supplier_imports', fn (Blueprint $table) => $table->dropColumn(['extraction_method', 'extraction_model']));
    }
};
