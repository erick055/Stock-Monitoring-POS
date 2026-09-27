<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 20)->default('active')->after('role')->index();
            $table->foreignId('approved_by')->nullable()->after('account_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('disabled_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable()->after('disabled_by');
            $table->string('disabled_reason', 500)->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['disabled_by']);
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'approved_by', 'approved_at', 'disabled_by', 'disabled_at', 'disabled_reason']);
        });
    }
};
