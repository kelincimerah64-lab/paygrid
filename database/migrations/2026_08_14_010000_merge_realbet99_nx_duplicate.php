<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $canonical = DB::table('merchants')->where('slug', 'realbet99')->first(['id']);
        $duplicate = DB::table('merchants')->where('slug', 'realbet99-nx')->first(['id']);

        if (! $canonical || ! $duplicate) {
            return;
        }

        DB::table('users')
            ->where('merchant_id', $duplicate->id)
            ->update(['merchant_id' => $canonical->id, 'updated_at' => now()]);

        DB::table('merchants')
            ->where('id', $duplicate->id)
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
            ->where('slug', 'realbet99-nx')
            ->update([
                'merchant_type' => 'cm',
                'topup_enabled' => true,
                'approval_status' => 'approved',
                'updated_at' => now(),
            ]);
    }
};
