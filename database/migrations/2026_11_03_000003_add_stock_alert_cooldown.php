<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_alert_states', function (Blueprint $table) {
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedBigInteger('pending_delivery_id')->nullable()->index();
        });
        // Preserve the cooldown of successful alerts sent before digests existed.
        $deliveries = DB::table('stock_alert_deliveries')->where('channel', 'email')->where('status', 'sent')
            ->whereNotNull('product_id')->select('product_id')->selectRaw('MAX(sent_at) as notified_at')->groupBy('product_id')->get();
        foreach ($deliveries as $delivery) {
            DB::table('stock_alert_states')->updateOrInsert(['product_id' => $delivery->product_id], [
                'last_notified_at' => $delivery->notified_at,
            ]);
        }
        Schema::table('stock_alert_deliveries', fn (Blueprint $table) => $table->longText('message')->change());
    }

    public function down(): void
    {
        Schema::table('stock_alert_states', fn (Blueprint $table) => $table->dropColumn(['last_notified_at', 'pending_delivery_id']));
    }
};
