<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('fee_menu_rates')->nullable()->after('fee_menu');
        });
        Schema::table('agents', function (Blueprint $table) {
            $table->json('fee_menu_rates')->nullable()->after('fee_menu');
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->json('fee_menu_rates')->nullable()->after('fee_menu');
        });

        $this->backfill('users', 'ma_fee_percent');
        $this->backfill('agents', 'default_agent_fee_percent');
        $this->backfill('merchants', 'merchant_mdr_percent');
    }

    private function backfill(string $table, string $percentColumn): void
    {
        DB::table($table)
            ->whereNotNull('fee_menu')
            ->where($percentColumn, '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $percentColumn) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'fee_menu_rates' => json_encode([$row->fee_menu => (float) $row->$percentColumn]),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fee_menu_rates');
        });
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('fee_menu_rates');
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('fee_menu_rates');
        });
    }
};
