<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_daily_metrics', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_total')->default(0)->after('amount_success');
            $table->unsignedBigInteger('amount_pending')->default(0)->after('amount_total');
            $table->unsignedBigInteger('amount_expired')->default(0)->after('amount_pending');
            $table->unsignedBigInteger('trx_success_processed')->default(0)->after('trx_success');
            $table->unsignedBigInteger('trx_success_unprocessed')->default(0)->after('trx_success_processed');
            $table->unsignedBigInteger('amount_success_processed')->default(0)->after('amount_success');
            $table->unsignedBigInteger('amount_success_unprocessed')->default(0)->after('amount_success_processed');
        });

        Schema::table('topup_requests', function (Blueprint $table) {
            $table->index(['merchant_id', 'submitted_at'], 'topup_merchant_submitted_idx');
            $table->index(['merchant_id', 'status', 'is_processed', 'submitted_at'], 'topup_merchant_status_processed_submitted_idx');
            $table->index(['merchant_id', 'payment_id'], 'topup_merchant_payment_idx');
            $table->index(['merchant_id', 'transaction_id'], 'topup_merchant_transaction_idx');
            $table->index(['merchant_id', 'rrn'], 'topup_merchant_rrn_idx');
            $table->index(['merchant_id', 'customer_reference'], 'topup_merchant_customer_ref_idx');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index(['merchant_id', 'created_at'], 'tickets_merchant_created_idx');
            $table->index(['merchant_id', 'ticket_no'], 'tickets_merchant_ticket_no_idx');
            $table->index(['merchant_id', 'reference'], 'tickets_merchant_reference_idx');
            $table->index(['merchant_id', 'client_reference'], 'tickets_merchant_client_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_merchant_client_ref_idx');
            $table->dropIndex('tickets_merchant_reference_idx');
            $table->dropIndex('tickets_merchant_ticket_no_idx');
            $table->dropIndex('tickets_merchant_created_idx');
        });

        Schema::table('topup_requests', function (Blueprint $table) {
            $table->dropIndex('topup_merchant_customer_ref_idx');
            $table->dropIndex('topup_merchant_rrn_idx');
            $table->dropIndex('topup_merchant_transaction_idx');
            $table->dropIndex('topup_merchant_payment_idx');
            $table->dropIndex('topup_merchant_status_processed_submitted_idx');
            $table->dropIndex('topup_merchant_submitted_idx');
        });

        Schema::table('merchant_daily_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'amount_total',
                'amount_pending',
                'amount_expired',
                'trx_success_processed',
                'trx_success_unprocessed',
                'amount_success_processed',
                'amount_success_unprocessed',
            ]);
        });
    }
};
