<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('merchants')->whereNotNull('merchant_key')->where('merchant_key', '<>', '')
            ->select(['id', 'merchant_key'])->orderBy('id')->get()
            ->each(fn ($merchant) => DB::table('merchants')->where('id', $merchant->id)->update([
                'merchant_key' => Crypt::encryptString($merchant->merchant_key),
            ]));
    }

    public function down(): void
    {
        DB::table('merchants')->whereNotNull('merchant_key')->where('merchant_key', '<>', '')
            ->select(['id', 'merchant_key'])->orderBy('id')->get()
            ->each(function ($merchant) {
                try {
                    DB::table('merchants')->where('id', $merchant->id)->update([
                        'merchant_key' => Crypt::decryptString($merchant->merchant_key),
                    ]);
                } catch (\Throwable) {
                    // Leave the value unchanged if rollback follows a partial migration.
                }
            });
    }
};
