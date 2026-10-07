<?php

namespace App\Http\Controllers\Api;

use App\Events\TelegramCsMonitorUpdated;
use App\Http\Controllers\Controller;
use App\Models\TelegramAbsence;
use App\Models\TelegramBotUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TelegramBotController extends Controller
{
    public function groupEvent(Request $request): JsonResponse
    {
        $this->authorizeCallback($request);

        $data = $request->validate([
            'event' => ['required', Rule::in(['joined', 'left', 'seen'])],
            'chat_id' => ['required', 'integer'],
            'telegram_user_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
        ]);

        $telegramUser = TelegramBotUser::query()->firstOrNew(['telegram_user_id' => $data['telegram_user_id']]);
        $telegramUser->fill([
            'username' => $data['username'] ?? null,
            'first_name' => $data['first_name'] ?? '',
            'group_chat_id' => $data['chat_id'],
        ]);
        if (! $telegramUser->exists) {
            $telegramUser->status = 'pending';
        }

        // 'seen' is passive detection (any group message from a sender we haven't met
        // yet) - it only needs to register the identity, not touch join/leave timing
        // since we genuinely don't know when they actually joined.
        if ($data['event'] === 'joined') {
            $telegramUser->joined_group_at = now();
            $telegramUser->left_group_at = null;
        } elseif ($data['event'] === 'left') {
            $telegramUser->left_group_at = now();
        }

        $telegramUser->save();
        event(new TelegramCsMonitorUpdated());

        return response()->json(['ok' => true]);
    }

    public function activationRequest(Request $request): JsonResponse
    {
        $this->authorizeCallback($request);

        $data = $request->validate([
            'telegram_user_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'chat_id' => ['required', 'integer'],
        ]);

        $telegramUser = TelegramBotUser::query()->firstOrNew(['telegram_user_id' => $data['telegram_user_id']]);
        $isNew = ! $telegramUser->exists;
        $telegramUser->fill([
            'username' => $data['username'] ?? null,
            'first_name' => $data['first_name'] ?? '',
            'dm_chat_id' => $data['chat_id'],
        ]);
        if ($isNew) {
            $telegramUser->status = 'pending';
        }
        $telegramUser->save();

        // Auto-issue a PIN on /activate whenever there isn't already a live one - not just
        // for a brand-new row. Passive group detection (see groupEvent 'seen') can create
        // this row before /activate ever runs, so "isNew" alone used to miss that case and
        // leave the admin to generate a PIN manually even on a first /activate.
        if (! $telegramUser->isActivated() && ! $telegramUser->pinIsActive()) {
            $telegramUser->generatePin();
        }
        event(new TelegramCsMonitorUpdated());

        return response()->json([
            'status' => $telegramUser->isActivated() ? 'already_activated' : 'pending_pin',
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $this->authorizeCallback($request);

        $data = $request->validate([
            'telegram_user_id' => ['required', 'integer'],
            'pin' => ['required', 'string'],
        ]);

        $telegramUser = TelegramBotUser::query()->where('telegram_user_id', $data['telegram_user_id'])->first();

        $valid = $telegramUser
            && $telegramUser->pinIsActive()
            && $telegramUser->readablePin() === $data['pin'];

        if (! $valid) {
            return response()->json(['status' => 'invalid_or_expired', 'name' => null]);
        }

        $telegramUser->forceFill([
            'status' => 'activated',
            'activated_at' => now(),
            'pin_encrypted' => null,
            'pin_expires_at' => null,
        ])->save();
        event(new TelegramCsMonitorUpdated());

        return response()->json(['status' => 'activated', 'name' => $telegramUser->displayName()]);
    }

    public function absen(Request $request): JsonResponse
    {
        $this->authorizeCallback($request);

        $data = $request->validate([
            'telegram_user_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'chat_id' => ['required', 'integer'],
            'message_date' => ['nullable', 'integer'],
        ]);

        $telegramUser = TelegramBotUser::query()->firstOrNew(['telegram_user_id' => $data['telegram_user_id']]);
        $telegramUser->fill([
            'username' => $data['username'] ?? null,
            'first_name' => $data['first_name'] ?? '',
            'group_chat_id' => $data['chat_id'],
        ]);
        if (! $telegramUser->exists) {
            $telegramUser->status = 'pending';
            $telegramUser->joined_group_at = now();
        }
        $telegramUser->save();

        $today = now('Asia/Jakarta')->toDateString();
        $absence = TelegramAbsence::query()->where('telegram_bot_user_id', $telegramUser->id)->whereDate('absen_date', $today)->first();
        $alreadyRecorded = $absence !== null;

        if (! $absence) {
            $absence = TelegramAbsence::query()->create([
                'telegram_bot_user_id' => $telegramUser->id,
                'absen_date' => $today,
                'absen_at' => now(),
                'is_verified' => $telegramUser->isActivated(),
            ]);
        }

        event(new TelegramCsMonitorUpdated());

        return response()->json([
            'status' => $alreadyRecorded ? 'already_recorded' : 'recorded',
            'name' => $telegramUser->displayName(),
            'time' => $absence->absen_at->timezone('Asia/Jakarta')->format('H:i'),
        ]);
    }

    private function authorizeCallback(Request $request): void
    {
        $token = (string) config('services.telegram_bot.callback_token');
        $provided = (string) $request->bearerToken();
        abort_unless($token !== '' && hash_equals($token, $provided), 401);
    }
}
