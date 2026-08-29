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
        Schema::table('gateway_sync_logs', function (Blueprint $table) {
            $table->index(['direction', 'status', 'created_at'], 'gateway_sync_logs_prune_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_sync_logs', function (Blueprint $table) {
            $table->dropIndex('gateway_sync_logs_prune_idx');
        });
    }
};
