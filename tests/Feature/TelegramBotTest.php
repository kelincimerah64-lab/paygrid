<?php

namespace Tests\Feature;

use App\Models\TelegramAbsence;
use App\Models\TelegramBotUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private function useTelegramBotToken(): self
    {
        config(['services.telegram_bot.callback_token' => 'bot-secret']);

        return $this;
    }

    private function monitor(): User
    {
        return User::factory()->create(['role' => 'cs_monitor', 'is_active' => true]);
    }

    public function test_telegram_endpoints_require_a_valid_bearer_token(): void
    {
        $this->useTelegramBotToken();

        $this->postJson('/api/telegram/activation-requests', ['telegram_user_id' => 1, 'chat_id' => 1])
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer wrong-token')
            ->postJson('/api/telegram/activation-requests', ['telegram_user_id' => 1, 'chat_id' => 1])
            ->assertStatus(401);
    }

    public function test_group_joined_event_creates_a_pending_telegram_user(): void
    {
        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/group-events', [
                'event' => 'joined',
                'chat_id' => -100123,
                'telegram_user_id' => 555,
                'username' => 'rama',
                'first_name' => 'Rama',
            ])
            ->assertOk()->assertJson(['ok' => true]);

        $telegramUser = TelegramBotUser::query()->where('telegram_user_id', 555)->firstOrFail();
        $this->assertSame('pending', $telegramUser->status);
        $this->assertNotNull($telegramUser->joined_group_at);
        $this->assertTrue($telegramUser->isSuspect());
    }

    public function test_group_left_event_clears_suspect_flag(): void
    {
        $telegramUser = TelegramBotUser::query()->create([
            'telegram_user_id' => 555, 'status' => 'pending', 'group_chat_id' => -100123, 'joined_group_at' => now(),
        ]);

        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/group-events', [
                'event' => 'left', 'chat_id' => -100123, 'telegram_user_id' => 555,
            ])
            ->assertOk();

        $this->assertFalse($telegramUser->fresh()->isSuspect());
    }

    public function test_activation_request_reports_pending_then_already_activated(): void
    {
        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activation-requests', [
                'telegram_user_id' => 777, 'username' => 'budi', 'first_name' => 'Budi', 'chat_id' => 777,
            ])
            ->assertOk()->assertJson(['status' => 'pending_pin']);

        TelegramBotUser::query()->where('telegram_user_id', 777)->update(['status' => 'activated']);

        $this->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activation-requests', [
                'telegram_user_id' => 777, 'username' => 'budi', 'first_name' => 'Budi', 'chat_id' => 777,
            ])
            ->assertOk()->assertJson(['status' => 'already_activated']);
    }

    public function test_activate_accepts_a_valid_unexpired_pin(): void
    {
        $staff = $this->monitor();
        $telegramUser = TelegramBotUser::query()->create([
            'telegram_user_id' => 888, 'status' => 'pending', 'user_id' => $staff->id,
            'pin_hash' => Hash::make('123456'), 'pin_expires_at' => now()->addMinutes(30),
        ]);

        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activate', ['telegram_user_id' => 888, 'pin' => '123456'])
            ->assertOk()->assertJson(['status' => 'activated', 'name' => $staff->name]);

        $telegramUser->refresh();
        $this->assertSame('activated', $telegramUser->status);
        $this->assertNull($telegramUser->pin_hash);
    }

    public function test_activate_rejects_wrong_or_expired_pin(): void
    {
        TelegramBotUser::query()->create([
            'telegram_user_id' => 999, 'status' => 'pending',
            'pin_hash' => Hash::make('123456'), 'pin_expires_at' => now()->subMinute(),
        ]);

        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activate', ['telegram_user_id' => 999, 'pin' => '123456'])
            ->assertOk()->assertJson(['status' => 'invalid_or_expired']);

        $this->assertSame('pending', TelegramBotUser::query()->where('telegram_user_id', 999)->first()->status);
    }

    public function test_absen_records_once_and_reports_already_recorded_on_a_second_call(): void
    {
        $telegramUser = TelegramBotUser::query()->create([
            'telegram_user_id' => 111, 'status' => 'activated', 'first_name' => 'Sari',
        ]);

        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/absen', ['telegram_user_id' => 111, 'chat_id' => -100123])
            ->assertOk()->assertJson(['status' => 'recorded']);

        $this->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/absen', ['telegram_user_id' => 111, 'chat_id' => -100123])
            ->assertOk()->assertJson(['status' => 'already_recorded']);

        $this->assertSame(1, TelegramAbsence::query()->where('telegram_bot_user_id', $telegramUser->id)->count());
    }

    /**
     * Deliberate stealth requirement: an unverified (suspect) user who sends /absen must get
     * the exact same success reply as a verified one, so they never learn they're flagged.
     * The real signal only shows up server-side via is_verified, surfaced on the dashboard.
     */
    public function test_absen_succeeds_silently_for_an_unverified_user_but_is_flagged_internally(): void
    {
        $response = $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/absen', [
                'telegram_user_id' => 222, 'username' => 'intruder', 'first_name' => 'Unknown', 'chat_id' => -100123,
            ])
            ->assertOk();

        $this->assertSame('recorded', $response->json('status'));
        $this->assertArrayNotHasKey('not_activated', $response->json());

        $telegramUser = TelegramBotUser::query()->where('telegram_user_id', 222)->firstOrFail();
        $this->assertSame('pending', $telegramUser->status);
        $absence = TelegramAbsence::query()->where('telegram_bot_user_id', $telegramUser->id)->firstOrFail();
        $this->assertFalse($absence->is_verified);
    }

    public function test_only_cs_monitor_and_superadmin_can_view_the_dashboard(): void
    {
        $this->seed();
        $this->actingAs($this->monitor())->get(route('cs-monitor.index'))->assertOk();

        $superadmin = User::query()->where('role', 'superadmin')->firstOrFail();
        $this->actingAs($superadmin)->get(route('cs-monitor.index'))->assertOk();

        $ma = User::query()->where('role', 'ma')->firstOrFail();
        $this->actingAs($ma)->get(route('cs-monitor.index'))->assertStatus(403);
    }

    public function test_generating_a_pin_links_the_staff_and_sets_a_thirty_minute_expiry(): void
    {
        $monitor = $this->monitor();
        $staff = User::factory()->create(['role' => 'cs_pusat', 'is_active' => true]);
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 333, 'status' => 'pending']);

        $response = $this->actingAs($monitor)->post(route('cs-monitor.generate-pin', $telegramUser), [
            'user_id' => $staff->id,
        ]);

        $response->assertRedirect();
        $telegramUser->refresh();
        $this->assertSame($staff->id, $telegramUser->user_id);
        $this->assertNotNull($telegramUser->pin_hash);
        $this->assertTrue($telegramUser->pin_expires_at->diffInMinutes(now()) <= 30);

        $pin = session('generated_pin')['pin'] ?? null;
        $this->assertNotNull($pin);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertTrue(Hash::check($pin, $telegramUser->pin_hash));
    }
}
