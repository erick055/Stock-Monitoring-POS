<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('email_enabled')->default(false);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('daily_summary_enabled')->default(false);
            $table->string('notification_email')->nullable();
            $table->string('notification_phone', 40)->nullable();
            $table->string('daily_summary_time', 5)->default('08:00');
            $table->timestamp('last_daily_summary_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products', 'product_id')->cascadeOnDelete();
            $table->string('severity', 20)->default('healthy');
            $table->unsignedInteger('last_stock')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products', 'product_id')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('alert_type', 40);
            $table->string('status', 20);
            $table->string('recipient')->nullable();
            $table->text('message');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alert_deliveries');
        Schema::dropIfExists('stock_alert_states');
        Schema::dropIfExists('stock_alert_settings');
    }
};
