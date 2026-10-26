<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // True when agent_fee_percent/ma_fee_percent were set for this store at approval;
            // later edits of the agent's or MA's own rates then leave this store alone.
            $table->boolean('fees_per_store')->default(false)->after('ma_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('fees_per_store');
        });
    }
};
