<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Points the separate `bottele` connection at a fresh in-memory sqlite DB and
 * runs the bot migrations, so bot tests never touch the main test database.
 */
trait UsesBotTeleDatabase
{
    protected function setUpUsesBotTeleDatabase(): void
    {
        config(['database.connections.bottele' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('bottele');

        $this->artisan('migrate', ['--database' => 'bottele', '--path' => 'database/migrations/bottele'])->assertSuccessful();

        config([
            'bottele.bot_token' => 'test-token',
            'bottele.webhook_secret' => 'hook-secret',
            'bottele.admin_chat_id' => '-1004445566939',
            'bottele.tech_chat_id' => '-1004445566939',
            'bottele.ip_approval_chat_id' => '-1004436542388',
            // A developer's local .env cutoff must not hide test tickets.
            'bottele.automation_since' => null,
        ]);
    }
}
