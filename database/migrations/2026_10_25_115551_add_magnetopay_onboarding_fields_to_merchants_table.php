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
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('va_callback_url')->nullable()->after('withdrawal_callback_url');
            $table->string('qris_settlement_type')->nullable()->after('va_callback_url');
            $table->string('va_settlement_type')->nullable()->after('qris_settlement_type');
            $table->boolean('ip_whitelist_enabled')->default(false)->after('va_settlement_type');
            $table->json('dashboard_ip_whitelist')->nullable()->after('ip_whitelist_enabled');
            $table->json('api_ip_whitelist')->nullable()->after('dashboard_ip_whitelist');
            $table->decimal('magnetopay_fee_1_percent', 8, 4)->default(0)->after('api_ip_whitelist');
            $table->decimal('magnetopay_fee_2_percent', 8, 4)->default(0)->after('magnetopay_fee_1_percent');
            $table->decimal('magnetopay_fee_3_percent', 8, 4)->default(0)->after('magnetopay_fee_2_percent');
            $table->decimal('magnetopay_fee_va_percent', 8, 4)->default(0)->after('magnetopay_fee_3_percent');
            $table->integer('magnetopay_fee_withdrawal_fixed')->nullable()->after('magnetopay_fee_va_percent');
            $table->decimal('magnetopay_fee_withdrawal_percent', 8, 4)->default(0)->after('magnetopay_fee_withdrawal_fixed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'va_callback_url',
                'qris_settlement_type',
                'va_settlement_type',
                'ip_whitelist_enabled',
                'dashboard_ip_whitelist',
                'api_ip_whitelist',
                'magnetopay_fee_1_percent',
                'magnetopay_fee_2_percent',
                'magnetopay_fee_3_percent',
                'magnetopay_fee_va_percent',
                'magnetopay_fee_withdrawal_fixed',
                'magnetopay_fee_withdrawal_percent',
            ]);
        });
    }
};
