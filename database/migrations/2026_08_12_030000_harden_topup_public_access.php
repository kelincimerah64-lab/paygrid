<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('topup_requests', 'public_token')) {
            Schema::table('topup_requests', function (Blueprint $table) {
                $table->uuid('public_token')->nullable()->after('idempotency_key');
            });
        }

        DB::table('topup_requests')
            ->whereNull('public_token')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('topup_requests')
                        ->where('id', $row->id)
                        ->update(['public_token' => (string) Str::uuid()]);
                }
            });

        if (! $this->hasIndex('topup_requests', 'topup_requests_public_token_unique')) {
            Schema::table('topup_requests', function (Blueprint $table) {
                $table->unique('public_token');
            });
        }

        Schema::table('topup_requests', function (Blueprint $table) {
            if ($this->hasIndex('topup_requests', 'topup_requests_idempotency_key_unique')) {
                $table->dropUnique('topup_requests_idempotency_key_unique');
            }
            if (! $this->hasIndex('topup_requests', 'topup_requests_merchant_id_idempotency_key_unique')) {
                $table->unique(['merchant_id', 'idempotency_key']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            if ($this->hasIndex('topup_requests', 'topup_requests_merchant_id_idempotency_key_unique')) {
                $table->dropUnique('topup_requests_merchant_id_idempotency_key_unique');
            }
            if (! $this->hasIndex('topup_requests', 'topup_requests_idempotency_key_unique')) {
                $table->unique('idempotency_key');
            }
            if ($this->hasIndex('topup_requests', 'topup_requests_public_token_unique')) {
                $table->dropUnique('topup_requests_public_token_unique');
            }
            if (Schema::hasColumn('topup_requests', 'public_token')) {
                $table->dropColumn('public_token');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return in_array($index, Schema::getIndexListing($table), true);
        }

        return collect(DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$index]))->isNotEmpty();
    }
};
