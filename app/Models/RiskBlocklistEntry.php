<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Стоп-лист антифрода. Хранит хэши, а не сами номера и адреса:
 * список мошенников — тоже персональные данные.
 */
class RiskBlocklistEntry extends Model
{
    protected $table = 'risk_blocklist';

    protected $guarded = [];

    public static function listed(string $type, string $hash): bool
    {
        return static::query()->where('type', $type)->where('value', $hash)->exists();
    }

    /**
     * Номер или адрес перед записью превращаем в хэш — открытым он тут не нужен.
     * Формат совпадает с тем, что считают заявки: иначе стоп-лист не сработает.
     */
    public static function hashFor(string $type, string $raw): string
    {
        if ($type === 'phone') {
            return Customer::phoneHash($raw) ?? hash_hmac('sha256', 'phone:'.$raw, (string) config('app.key'));
        }

        return hash_hmac('sha256', trim($raw), (string) config('app.key'));
    }
}
