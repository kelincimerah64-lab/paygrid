<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('merchants')
            ->where('slug', 'oc-liga-pools')
            ->update([
                'merchant_type' => 'script',
                'topup_enabled' => false,
                'topup_url' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('merchants')
            ->where('slug', 'oc-liga-pools')
            ->update([
                'merchant_type' => 'cm',
                'topup_enabled' => true,
                'topup_url' => 'http://oc-liga-pools.15.232.137.74.nip.io/topup',
                'updated_at' => now(),
            ]);
    }
};
