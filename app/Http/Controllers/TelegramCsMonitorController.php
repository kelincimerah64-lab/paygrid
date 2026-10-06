<?php

namespace App\Http\Controllers;

use App\Models\TelegramAbsence;
use App\Models\TelegramBotUser;
use App\Models\User;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TelegramCsMonitorController extends Controller
{
    public function index(): View
    {
        $telegramUsers = TelegramBotUser::query()->with(['user', 'pinGeneratedBy'])->orderByDesc('created_at')->get();

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
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'generatedPin' => session('generated_pin'),
        ]);
    }

    public function generatePin(Request $request, TelegramBotUser $telegramUser): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => [$telegramUser->user_id ? 'nullable' : 'required', 'integer', 'exists:users,id'],
        ]);

        $pin = (string) random_int(100000, 999999);

        $telegramUser->forceFill([
            'user_id' => $data['user_id'] ?? $telegramUser->user_id,
            'pin_hash' => Hash::make($pin),
            'pin_expires_at' => now()->addMinutes(30),
            'pin_generated_by' => $request->user()->id,
        ])->save();

        return back()->with('generated_pin', [
            'name' => $telegramUser->displayName(),
            'pin' => $pin,
            'expires_at' => $telegramUser->pin_expires_at,
        ]);
    }
}
