<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            $table->string('customer_reference')->nullable()->index()->after('merchant_id');
            $table->string('idempotency_key')->nullable()->unique()->after('customer_reference');
            $table->text('qr_string')->nullable()->after('gateway_ref_id');
            $table->text('payment_url')->nullable()->after('qr_string');
            $table->string('gateway_status')->nullable()->after('status');
            $table->timestamp('last_synced_at')->nullable()->index()->after('callback_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['customer_reference', 'idempotency_key', 'qr_string', 'payment_url', 'gateway_status', 'last_synced_at']);
        });
    }
};
