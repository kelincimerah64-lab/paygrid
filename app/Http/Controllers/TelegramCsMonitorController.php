<?php

namespace App\Http\Controllers;

use App\Models\TelegramAbsence;
use App\Models\TelegramBotUser;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
}
