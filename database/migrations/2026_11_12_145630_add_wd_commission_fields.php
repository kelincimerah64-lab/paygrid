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
        Schema::table('commission_config_days', function (Blueprint $table) {
            $table->decimal('wd_rate_percent', 8, 4)->default(0)->after('rate_percent');
        });

        Schema::table('merchant_commission_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('wd_volume')->default(0)->after('volume');
            $table->decimal('wd_rate_percent', 8, 4)->default(0)->after('rate_percent');
            $table->unsignedBigInteger('wd_commission_amount')->default(0)->after('commission_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commission_config_days', function (Blueprint $table) {
            $table->dropColumn('wd_rate_percent');
        });

        Schema::table('merchant_commission_reports', function (Blueprint $table) {
            $table->dropColumn(['wd_volume', 'wd_rate_percent', 'wd_commission_amount']);
        });
    }
};
