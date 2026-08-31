<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DROPPED_KEYS = ['based', 'h_plus_1_api'];

    private const RELABELS = [
        'h_plus_1' => ['label' => 'Base H1', 'sort_order' => 0],
        'everyday' => ['label' => 'Base ED', 'sort_order' => 1],
        'same_day' => ['label' => 'Base SD', 'sort_order' => 2],
        'everyday_api' => ['label' => 'CM ED', 'sort_order' => 3],
        'h_plus_1_sc' => ['label' => 'Script H1', 'sort_order' => 4],
        'everyday_sc' => ['label' => 'Script ED', 'sort_order' => 5],
        'same_day_sc' => ['label' => 'Script SD', 'sort_order' => 6],
        'same_day_api' => ['label' => 'CM SD', 'sort_order' => 7],
    ];

    public function up(): void
    {
        foreach (self::RELABELS as $key => $attrs) {
            DB::table('fee_menus')->where('key', $key)->update([
                'label' => $attrs['label'],
                'sort_order' => $attrs['sort_order'],
                'updated_at' => now(),
            ]);
        }

        DB::table('fee_menus')->whereIn('key', self::DROPPED_KEYS)->delete();

        foreach (['users', 'agents', 'merchants'] as $table) {
            $rows = DB::table($table)->whereNotNull('fee_menu_rates')->get(['id', 'fee_menu_rates']);
            foreach ($rows as $row) {
                $rates = json_decode($row->fee_menu_rates, true) ?? [];
                if (array_intersect_key($rates, array_flip(self::DROPPED_KEYS)) === []) {
                    continue;
                }
                foreach (self::DROPPED_KEYS as $key) {
                    unset($rates[$key]);
                }
                DB::table($table)->where('id', $row->id)->update([
                    'fee_menu_rates' => json_encode($rates),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Original labels/order/dropped rows are not restored - this is a
        // one-way business-driven catalog restructure, matching the
        // established pattern of prior data-only migrations in this repo.
    }
};
