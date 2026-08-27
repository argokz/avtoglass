<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'settled_at' => 'datetime',
            'amount' => 'decimal:2',
            'payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->token ??= (string) Str::uuid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** Наличные держит у себя человек, пока не сдаст в кассу. */
    public function isCash(): bool
    {
        return $this->provider === 'cash';
    }

    public function isSettled(): bool
    {
        return ! $this->isCash() || $this->settled_at !== null;
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * Наличные, которые взяли у клиента, но ещё не сдали в кассу.
     *
     * Только успешные: отклонённый или возвращённый платёж на руках
     * не лежит, и в сводке инкассации ему делать нечего.
     */
    public function scopeCashOnHand(Builder $query): Builder
    {
        return $query->where('provider', 'cash')
            ->where('status', PaymentStatus::Succeeded)
            ->whereNull('settled_at')
            ->whereNotNull('received_by');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Pending);
    }

    /**
     * Ссылка протухла: клиент открыл оплату и не заплатил.
     * Держать под такой платёж резерв стекла дольше нельзя.
     */
    public function isExpired(): bool
    {
        return $this->status === PaymentStatus::Pending
            && $this->created_at->addMinutes((int) config('payments.ttl_minutes'))->isPast();
    }

    public function isPayable(): bool
    {
        return $this->status === PaymentStatus::Pending && ! $this->isExpired();
    }
}
