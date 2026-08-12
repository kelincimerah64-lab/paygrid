<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            $table->index(['merchant_id', 'status', 'submitted_at'], 'topup_merchant_status_submitted_idx');
            $table->index(['gateway', 'data_source', 'submitted_at'], 'topup_gateway_source_submitted_idx');
            $table->index(['merchant_id', 'gateway_ref_id'], 'topup_merchant_gateway_ref_idx');
            $table->index(['processed_by_user_id', 'processed_at'], 'topup_processed_user_at_idx');
        });

        Schema::table('merchant_daily_metrics', function (Blueprint $table) {
            $table->index(['metric_date', 'agent_id'], 'metrics_date_agent_idx');
            $table->index(['metric_date', 'gateway'], 'metrics_date_gateway_idx');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index(['merchant_id', 'status', 'created_at'], 'tickets_merchant_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_merchant_status_created_idx');
        });

        Schema::table('merchant_daily_metrics', function (Blueprint $table) {
            $table->dropIndex('metrics_date_agent_idx');
            $table->dropIndex('metrics_date_gateway_idx');
        });

        Schema::table('topup_requests', function (Blueprint $table) {
            $table->dropIndex('topup_merchant_status_submitted_idx');
            $table->dropIndex('topup_gateway_source_submitted_idx');
            $table->dropIndex('topup_merchant_gateway_ref_idx');
            $table->dropIndex('topup_processed_user_at_idx');
        });
    }
};
