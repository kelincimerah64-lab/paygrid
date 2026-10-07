<?php

namespace App\Http\Controllers;

use App\Models\TelegramAbsence;
use App\Models\TelegramBotUser;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class TelegramCsMonitorController extends Controller
{
    public function index(Request $request): View
    {
        $selectedDate = $this->resolveDate($request->query('date'));

        $telegramUsers = TelegramBotUser::query()->orderByDesc('created_at')->get();
        $absencesForDate = TelegramAbsence::query()
            ->whereDate('absen_date', $selectedDate)
            ->get()
            ->keyBy('telegram_bot_user_id');

        $activated = $telegramUsers->filter(fn (TelegramBotUser $u) => $u->isActivated())->values();
        $suspects = $telegramUsers->filter(fn (TelegramBotUser $u) => $u->isSuspect())->values();
        $hadirCount = $activated->filter(fn (TelegramBotUser $u) => $absencesForDate->has($u->id))->count();
        $groupChatIds = $telegramUsers->pluck('group_chat_id')->filter()->unique()->values()->all();

        return view('paygrid.cs-monitor', [
            'roleLabel' => 'CS Monitor',
            'menus' => app(MenuBuilder::class)->csMonitor(),
            'active' => 'cs-monitor',
            'suspects' => $suspects,
            'activated' => $activated,
            'left' => $telegramUsers->filter(fn (TelegramBotUser $u) => ! $u->isActivated() && $u->left_group_at !== null)->values(),
            'absencesForDate' => $absencesForDate,
            'selectedDate' => $selectedDate,
            'kpi' => [
                'semua' => $activated->count() + $suspects->count(),
                'total' => $activated->count(),
                'hadir' => $hadirCount,
                'belum' => $activated->count() - $hadirCount,
                'grup' => $this->telegramGroupMemberCount($groupChatIds),
            ],
        ]);
    }

    public function generatePin(Request $request, TelegramBotUser $telegramUser): RedirectResponse
    {
        $telegramUser->generatePin($request->user()->id);

        return back();
    }

    private function resolveDate(?string $date): string
    {
        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return now('Asia/Jakarta')->toDateString();
    }

    /**
     * Sum of real member counts across every group the bot has ever seen activity in
     * (group_chat_id is recorded automatically as people interact) - the "ground truth"
     * to compare against how many the system has actually identified. Null (not the
     * count of 0) means "can't tell" - no bot token configured, or every lookup failed -
     * so the dashboard can show "-" instead of a misleading zero.
     */
    private function telegramGroupMemberCount(array $chatIds): ?int
    {
        $token = (string) config('services.telegram_bot.bot_token');
        if ($token === '') {
            return null;
        }

        $total = 0;
        $any = false;

        foreach ($chatIds as $chatId) {
            $count = Cache::remember("telegram-member-count:{$chatId}", 60, function () use ($token, $chatId) {
                try {
                    $response = Http::timeout(5)->get("https://api.telegram.org/bot{$token}/getChatMemberCount", ['chat_id' => $chatId]);

                    return $response->successful() && $response->json('ok') ? (int) $response->json('result') : null;
                } catch (Throwable) {
                    return null;
                }
            });

            if ($count !== null) {
                $total += $count;
                $any = true;
            }
        }

        return $any ? $total : null;
    }
}
