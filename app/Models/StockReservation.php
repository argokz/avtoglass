<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'held',
        'quantity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** Брони, которые сейчас реально держат склад. */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Held);
    }

    /** Просроченные брони — их снимает планировщик. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->held()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isHeld(): bool
    {
        return $this->status === ReservationStatus::Held;
    }
}
