<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_returns', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->index();
        });
        // Historical approvals have no dedicated timestamp. updated_at is the
        // closest available record of the review (returned_at for older imports).
        DB::table('customer_returns')->where('status', 'approved')->update([
            'approved_at' => DB::raw('COALESCE(updated_at, returned_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('customer_returns', fn (Blueprint $table) => $table->dropColumn('approved_at'));
    }
};
