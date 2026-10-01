<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The channel column added in add_channel_column_to_topup_requests_table was
 * VIRTUAL, meaning MySQL/MariaDB still has to re-parse gateway_payload's JSON
 * for every matched row whenever the column is read outside of a pure
 * index-only scan - GROUP BY channel needs the actual value (not just the
 * index), so it was recomputing JSON on every row: GROUP BY channel alone
 * measured ~17s at ~130k rows, same order as before the column existed.
 * STORED materializes the value on write instead, so grouping is a plain
 * column read like any other. This requires the whole table to backfill
 * once during the ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX topup_requests_channel_index');
            DB::statement('ALTER TABLE topup_requests DROP COLUMN channel');
            DB::statement("ALTER TABLE topup_requests ADD COLUMN channel TEXT GENERATED ALWAYS AS (
                COALESCE(
                    NULLIF(json_extract(gateway_payload, '\$.issuer_name'), ''),
                    NULLIF(json_extract(gateway_payload, '\$.bank_name'), '')
                )
            ) STORED");
            DB::statement('CREATE INDEX topup_requests_channel_index ON topup_requests (channel)');

            return;
        }

        DB::statement('ALTER TABLE topup_requests DROP INDEX topup_requests_channel_index');
        DB::statement('ALTER TABLE topup_requests DROP COLUMN channel');
        DB::statement("ALTER TABLE topup_requests ADD COLUMN channel VARCHAR(191) GENERATED ALWAYS AS (
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(gateway_payload, '\$.issuer_name')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(gateway_payload, '\$.bank_name')), '')
            )
        ) STORED");
        DB::statement('ALTER TABLE topup_requests ADD INDEX topup_requests_channel_index (channel)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX topup_requests_channel_index');
        } else {
            DB::statement('ALTER TABLE topup_requests DROP INDEX topup_requests_channel_index');
        }
        DB::statement('ALTER TABLE topup_requests DROP COLUMN channel');
    }
};
