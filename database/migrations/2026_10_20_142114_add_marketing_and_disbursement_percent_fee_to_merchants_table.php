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
            $table->decimal('disbursement_fee_percent', 8, 4)->default(0)->after('disbursement_fee_fixed');
            $table->decimal('marketing_fee_percent', 8, 4)->default(0)->after('disbursement_fee_percent');
            $table->integer('marketing_fee_fixed')->nullable()->after('marketing_fee_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['disbursement_fee_percent', 'marketing_fee_percent', 'marketing_fee_fixed']);
        });
    }
};
