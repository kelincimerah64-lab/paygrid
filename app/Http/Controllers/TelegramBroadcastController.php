<?php

namespace App\Http\Controllers;

use App\Models\TelegramBroadcastMessage;
use App\Services\Navigation\MenuBuilder;
use App\Services\Telegram\TelegramBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TelegramBroadcastController extends Controller
{
    public function index(TelegramBroadcaster $telegram): View
    {
        return view('paygrid.cs-pusat-broadcast', [
            'roleLabel' => 'CS Pusat',
            'menus' => app(MenuBuilder::class)->centerSupport(),
            'active' => 'broadcast-telegram',
            'groups' => $telegram->groups(),
            'configured' => $telegram->isConfigured(),
            'history' => TelegramBroadcastMessage::query()->with('sender')->latest('id')->simplePaginate(50),
        ]);
    }

    public function send(Request $request, TelegramBroadcaster $telegram): RedirectResponse
    {
        abort_unless($telegram->isConfigured(), 422, 'Broadcast Telegram belum dikonfigurasi.');
        $groups = collect($telegram->groups())->keyBy('chat_id');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4096'],
            'chat_ids' => ['nullable', 'array'],
            'chat_ids.*' => ['required', 'string', 'distinct', Rule::in($groups->keys()->all())],
        ]);

        // No group ticked means broadcast to every configured group.
        $targets = empty($data['chat_ids']) ? $groups : $groups->only($data['chat_ids']);
        $batchId = (string) Str::uuid();

        $summary = $targets->map(function (array $group) use ($telegram, $data, $batchId, $request) {
            $messageId = $telegram->send($group['chat_id'], $data['message']);
            TelegramBroadcastMessage::query()->create([
                'batch_id' => $batchId,
                'sent_by' => $request->user()->id,
                'message' => $data['message'],
                'group_name' => $group['name'],
                'chat_id' => $group['chat_id'],
                'telegram_message_id' => $messageId,
                'status' => $messageId === null ? 'failed' : 'sent',
            ]);

            return $group['name'].': '.($messageId === null ? 'gagal' : 'terkirim');
        })->implode(', ');

        return back()->with('status', 'Broadcast Telegram - '.$summary.'.');
    }

    public function destroy(Request $request, TelegramBroadcastMessage $broadcastMessage, TelegramBroadcaster $telegram): RedirectResponse
    {
        abort_unless($broadcastMessage->status === 'sent', 422, 'Pesan ini tidak bisa dihapus.');

        if (! $telegram->delete($broadcastMessage->chat_id, (int) $broadcastMessage->telegram_message_id)) {
            return back()->withErrors(['broadcast' => 'Gagal menghapus pesan di '.$broadcastMessage->group_name.'. Telegram hanya mengizinkan hapus pesan bot maksimal 48 jam setelah dikirim.']);
        }

        $broadcastMessage->update([
            'status' => 'deleted',
            'deleted_from_telegram_at' => now(),
            'deleted_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Pesan di '.$broadcastMessage->group_name.' berhasil dihapus.');
    }
}
