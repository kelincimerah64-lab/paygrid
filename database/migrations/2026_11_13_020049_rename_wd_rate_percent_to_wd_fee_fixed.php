<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * wd_rate_percent was a percent-of-volume rate - turns out the real
     * business rule for withdrawal fees is always a flat IDR amount per
     * withdrawal transaction, never a percentage (confirmed zero non-zero
     * rows in production before this ran, so no data to preserve). Dropping
     * and recreating as an integer rather than changing the column type in
     * place, to avoid a doctrine/dbal dependency for ->change().
     */
    public function up(): void
    {
        Schema::table('commission_config_days', function (Blueprint $table) {
            $table->dropColumn('wd_rate_percent');
        });
        Schema::table('commission_config_days', function (Blueprint $table) {
            $table->unsignedInteger('wd_fee_fixed')->default(0)->after('rate_percent');
        });

        Schema::table('merchant_commission_reports', function (Blueprint $table) {
            $table->dropColumn('wd_rate_percent');
        });
        Schema::table('merchant_commission_reports', function (Blueprint $table) {
            $table->unsignedInteger('wd_fee_fixed')->default(0)->after('rate_percent');
            $table->unsignedInteger('wd_trx_count')->default(0)->after('wd_volume');
        });
    }

    public function down(): void
    {
        Schema::table('commission_config_days', function (Blueprint $table) {
            $table->dropColumn('wd_fee_fixed');
        });
        Schema::table('commission_config_days', function (Blueprint $table) {
            $table->decimal('wd_rate_percent', 8, 4)->default(0)->after('rate_percent');
        });

        Schema::table('merchant_commission_reports', function (Blueprint $table) {
            $table->dropColumn(['wd_fee_fixed', 'wd_trx_count']);
        });
        Schema::table('merchant_commission_reports', function (Blueprint $table) {
            $table->decimal('wd_rate_percent', 8, 4)->default(0)->after('rate_percent');
        });
    }
};
