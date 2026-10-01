<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extracts the payment channel (bank/e-wallet) from gateway_payload into a real,
 * indexed column. analyticsChannelReliability() previously grouped by a raw
 * JSON_EXTRACT expression evaluated per row with no index to lean on - at
 * ~130k matched rows that alone took ~7s. A generated column lets MySQL/MariaDB
 * (and SQLite in tests) maintain an index on the extracted value instead of
 * re-parsing the JSON payload on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE topup_requests ADD COLUMN channel TEXT GENERATED ALWAYS AS (
                COALESCE(
                    NULLIF(json_extract(gateway_payload, '\$.issuer_name'), ''),
                    NULLIF(json_extract(gateway_payload, '\$.bank_name'), '')
                )
            ) VIRTUAL");
            DB::statement('CREATE INDEX topup_requests_channel_index ON topup_requests (channel)');

            return;
        }

        DB::statement("ALTER TABLE topup_requests ADD COLUMN channel VARCHAR(191) GENERATED ALWAYS AS (
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(gateway_payload, '\$.issuer_name')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(gateway_payload, '\$.bank_name')), '')
            )
        ) VIRTUAL");
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
