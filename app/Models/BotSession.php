<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Telegram support bot conversation state (former "session" sheet), keyed by (user_id, chat_id). */
class BotSession extends Model
{
    protected $connection = 'bottele';

    public $timestamps = false;

    protected $guarded = ['id'];

    public static function for(int|string $userId, int|string $chatId): ?self
    {
        return static::query()->where('user_id', $userId)->where('chat_id', $chatId)->first();
    }

    public static function upsertFor(int|string $userId, int|string $chatId, array $values): self
    {
        return static::query()->updateOrCreate(['user_id' => $userId, 'chat_id' => $chatId], $values);
    }
}
