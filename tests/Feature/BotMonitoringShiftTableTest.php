<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class BotMonitoringShiftTableTest extends TestCase
{
    use RefreshDatabase;
    use UsesBotTeleDatabase;

    public function test_cs_pusat_and_ma_see_the_tech_support_shift_table(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Jakarta'));
        DB::connection('bottele')->table('bot_attendances')->insert([
            'user_id' => 702, 'name' => 'Tamara', 'username' => 'tamara', 'status' => 'HADIR',
            'check_in_at' => '2026-10-26 08:00:00', 'expires_at' => '2026-10-26 16:00:00', 'active_ticket_id' => 'HGT-9',
        ]);

        foreach (['cs_pusat' => 'center-support.bot-monitoring', 'ma' => 'ma.bot-monitoring'] as $role => $route) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route($route))
                ->assertOk()
                ->assertSeeInOrder(['Data Shift Tech Support', '1 on shift', 'Tamara', '@tamara', 'On shift', '26/10/26 08.00 WIB', '26/10/26 16.00 WIB', 'HGT-9']);
        }
    }
}
