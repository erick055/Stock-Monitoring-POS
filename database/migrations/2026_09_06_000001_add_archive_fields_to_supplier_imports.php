<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_imports', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->unique()->after('source_filename');
            $table->timestamp('archived_at')->nullable()->after('approved_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_imports', function (Blueprint $table) {
            $table->dropUnique(['file_hash']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['file_hash', 'archived_at']);
        });
    }
};
