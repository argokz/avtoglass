<?php

namespace App\Models;

use App\Enums\InteractionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => InteractionType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Системная запись — след действия, а не общение с клиентом.
     * Автор не указывается: в момент фоновой обработки его может и не быть.
     */
    public static function system(Order $order, string $body): self
    {
        return static::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'user_id' => self::авторЗаписи(),
            'type' => InteractionType::System,
            'body' => $body,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Кто пишет в журнал — и существует ли он ещё.
     *
     * Сотрудника уволили и удалили, а вкладка у него осталась открытой:
     * Auth::id() в этом случае отдаёт идентификатор прямо из сессии, и запись
     * не проходит по внешнему ключу. Падает при этом не админка, а оформление
     * заказа у покупателя — там же, в транзакции, где создаётся заявка.
     *
     * Лишний SELECT здесь не жалко: журнал пишется по событию заказа,
     * а не в цикле.
     */
    private static function авторЗаписи(): ?int
    {
        $id = auth()->user()?->getKey();

        if ($id === null) {
            return null;
        }

        return User::query()->whereKey($id)->exists() ? (int) $id : null;
    }

    /** Только то, что говорили с клиентом, без системного журнала. */
    public function scopeHumanOnly(Builder $query): Builder
    {
        return $query->where('type', '!=', InteractionType::System->value);
    }
}
