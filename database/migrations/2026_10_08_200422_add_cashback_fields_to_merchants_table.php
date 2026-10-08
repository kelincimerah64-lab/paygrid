<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('cashback_agent_percent', 8, 4)->default(0)->after('payin_fee_percent');
            $table->integer('cashback_trx_toko_amount')->default(0)->after('cashback_agent_percent');
            $table->integer('cashback_trx_agent_amount')->default(0)->after('cashback_trx_toko_amount');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['cashback_agent_percent', 'cashback_trx_toko_amount', 'cashback_trx_agent_amount']);
        });
    }
};
