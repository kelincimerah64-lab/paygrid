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
    public function index(): View
    {
        $telegramUsers = TelegramBotUser::query()->orderByDesc('created_at')->get();

        return view('paygrid.cs-monitor', [
            'roleLabel' => 'CS Monitor',
            'menus' => app(MenuBuilder::class)->csMonitor(),
            'active' => 'cs-monitor',
            'suspects' => $telegramUsers->filter(fn (TelegramBotUser $u) => $u->isSuspect())->values(),
            'activated' => $telegramUsers->filter(fn (TelegramBotUser $u) => $u->isActivated())->values(),
            'left' => $telegramUsers->filter(fn (TelegramBotUser $u) => ! $u->isActivated() && $u->left_group_at !== null)->values(),
            'todaysAbsences' => TelegramAbsence::query()
                ->whereDate('absen_date', now('Asia/Jakarta')->toDateString())
                ->get()
                ->keyBy('telegram_bot_user_id'),
        ]);
    }

    public function generatePin(Request $request, TelegramBotUser $telegramUser): RedirectResponse
    {
        $telegramUser->generatePin($request->user()->id);

        return back();
    }
}
