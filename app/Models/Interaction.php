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
            'user_id' => auth()->id(),
            'type' => InteractionType::System,
            'body' => $body,
            'occurred_at' => now(),
        ]);
    }

    /** Только то, что говорили с клиентом, без системного журнала. */
    public function scopeHumanOnly(Builder $query): Builder
    {
        return $query->where('type', '!=', InteractionType::System->value);
    }
}
