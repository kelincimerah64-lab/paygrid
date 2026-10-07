<?php

namespace Tests\Feature;

use App\Models\TelegramAbsence;
use App\Models\TelegramBotUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_activation_request_auto_generates_a_pin_for_a_brand_new_pending_user(): void
    {
        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activation-requests', [
                'telegram_user_id' => 777, 'username' => 'budi', 'first_name' => 'Budi', 'chat_id' => 777,
            ])
            ->assertOk()->assertJson(['status' => 'pending_pin']);

        $telegramUser = TelegramBotUser::query()->where('telegram_user_id', 777)->firstOrFail();
        $this->assertTrue($telegramUser->pinIsActive());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $telegramUser->readablePin());
    }

    public function test_activation_request_reports_already_activated_and_does_not_touch_pin(): void
    {
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 777, 'status' => 'activated']);

        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activation-requests', [
                'telegram_user_id' => 777, 'username' => 'budi', 'first_name' => 'Budi', 'chat_id' => 777,
            ])
            ->assertOk()->assertJson(['status' => 'already_activated']);

        $this->assertFalse($telegramUser->fresh()->pinIsActive());
    }

    public function test_activate_accepts_a_valid_unexpired_pin(): void
    {
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 888, 'status' => 'pending', 'first_name' => 'Budi']);
        $telegramUser->generatePin();

        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activate', ['telegram_user_id' => 888, 'pin' => $telegramUser->readablePin()])
            ->assertOk()->assertJson(['status' => 'activated', 'name' => 'Budi']);

        $telegramUser->refresh();
        $this->assertSame('activated', $telegramUser->status);
        $this->assertNull($telegramUser->pin_encrypted);
    }

    public function test_activate_rejects_wrong_or_expired_pin(): void
    {
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 999, 'status' => 'pending']);
        $telegramUser->generatePin();
        $telegramUser->forceFill(['pin_expires_at' => now()->subMinute()])->save();

        $this->useTelegramBotToken()->withHeader('Authorization', 'Bearer bot-secret')
            ->postJson('/api/telegram/activate', ['telegram_user_id' => 999, 'pin' => $telegramUser->readablePin()])
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

    public function test_dashboard_shows_absen_status_for_suspects_too_and_defaults_to_today(): void
    {
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 123, 'status' => 'pending', 'first_name' => 'Intruder']);
        TelegramAbsence::query()->create([
            'telegram_bot_user_id' => $telegramUser->id, 'absen_date' => now('Asia/Jakarta')->toDateString(),
            'absen_at' => now(), 'is_verified' => false,
        ]);

        $response = $this->actingAs($this->monitor())->get(route('cs-monitor.index'));

        $response->assertOk()->assertSee('Hadir');
        $response->assertViewHas('selectedDate', now('Asia/Jakarta')->toDateString());
    }

    public function test_dashboard_kpi_counts_only_activated_users_for_the_selected_date(): void
    {
        $present = TelegramBotUser::query()->create(['telegram_user_id' => 201, 'status' => 'activated']);
        TelegramBotUser::query()->create(['telegram_user_id' => 202, 'status' => 'activated']);
        TelegramBotUser::query()->create(['telegram_user_id' => 203, 'status' => 'pending']);
        TelegramAbsence::query()->create([
            'telegram_bot_user_id' => $present->id, 'absen_date' => now('Asia/Jakarta')->toDateString(),
            'absen_at' => now(), 'is_verified' => true,
        ]);

        $response = $this->actingAs($this->monitor())->get(route('cs-monitor.index'));

        $response->assertViewHas('kpi', ['total' => 2, 'hadir' => 1, 'belum' => 1]);
    }

    public function test_dashboard_respects_a_past_date_filter(): void
    {
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 301, 'status' => 'activated']);
        $yesterday = now('Asia/Jakarta')->subDay()->toDateString();
        TelegramAbsence::query()->create([
            'telegram_bot_user_id' => $telegramUser->id, 'absen_date' => $yesterday, 'absen_at' => now()->subDay(), 'is_verified' => true,
        ]);

        $todayResponse = $this->actingAs($this->monitor())->get(route('cs-monitor.index'));
        $todayResponse->assertViewHas('kpi', ['total' => 1, 'hadir' => 0, 'belum' => 1]);

        $pastResponse = $this->actingAs($this->monitor())->get(route('cs-monitor.index', ['date' => $yesterday]));
        $pastResponse->assertViewHas('kpi', ['total' => 1, 'hadir' => 1, 'belum' => 0]);
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

    public function test_generating_a_pin_needs_no_staff_selection_and_sets_a_thirty_minute_expiry(): void
    {
        $monitor = $this->monitor();
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 333, 'status' => 'pending']);

        $response = $this->actingAs($monitor)->post(route('cs-monitor.generate-pin', $telegramUser), []);

        $response->assertRedirect();
        $telegramUser->refresh();
        $this->assertSame($monitor->id, $telegramUser->pin_generated_by);
        $this->assertTrue($telegramUser->pinIsActive());
        $this->assertTrue($telegramUser->pin_expires_at->diffInMinutes(now()) <= 30);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $telegramUser->readablePin());
    }

    public function test_regenerating_a_pin_replaces_the_previous_one(): void
    {
        $monitor = $this->monitor();
        $telegramUser = TelegramBotUser::query()->create(['telegram_user_id' => 444, 'status' => 'pending']);
        $telegramUser->generatePin();
        $firstPin = $telegramUser->readablePin();

        $this->actingAs($monitor)->post(route('cs-monitor.generate-pin', $telegramUser), []);

        $telegramUser->refresh();
        $this->assertNotSame($firstPin, $telegramUser->readablePin());
    }
}
