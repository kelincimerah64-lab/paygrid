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
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->decimal('disbursement_fee_percent', 8, 4)->default(0)->after('fee_menu_rates');
            $table->integer('disbursement_fee_fixed')->nullable()->after('disbursement_fee_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->dropColumn(['disbursement_fee_percent', 'disbursement_fee_fixed']);
        });
    }
};
