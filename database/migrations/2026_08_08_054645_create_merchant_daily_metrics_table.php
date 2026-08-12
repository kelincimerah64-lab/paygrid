<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merchant_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->date('metric_date')->index();
            $table->string('gateway')->default('hilogate');
            $table->string('data_source')->default('gateway_pull');
            $table->unsignedBigInteger('trx_total')->default(0);
            $table->unsignedBigInteger('trx_success')->default(0);
            $table->unsignedBigInteger('trx_pending')->default(0);
            $table->unsignedBigInteger('trx_expired')->default(0);
            $table->unsignedBigInteger('amount_success')->default(0);
            $table->unsignedBigInteger('net_success')->default(0);
            $table->unsignedBigInteger('fee_total')->default(0);
            $table->unsignedBigInteger('settled_total')->default(0);
            $table->unsignedBigInteger('ticket_total')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'metric_date', 'data_source'], 'metrics_merchant_date_source_unique');
            $table->index(['agent_id', 'metric_date'], 'metrics_agent_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_daily_metrics');
    }
};
