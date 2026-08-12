<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaygridSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function value(string $key, string $default): string
    {
        return (string) (self::query()->whereKey($key)->value('value') ?? $default);
    }
}
