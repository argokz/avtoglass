<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'provider' => 'webkassa',
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'amount' => 'decimal:2',
            'payload' => 'array',
            'fiscal_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Чеки, которые ещё можно попробовать выбить.
     * Исчерпавшие попытки сюда не попадают — их разбирает человек,
     * иначе очередь будет вечно долбиться в сломанную кассу.
     */
    public function scopeRetryable(Builder $query): Builder
    {
        return $query->whereIn('status', [ReceiptStatus::Pending->value, ReceiptStatus::Failed->value])
            ->where('attempts', '<', (int) config('fiscal.webkassa.max_attempts'));
    }

    public function isIssued(): bool
    {
        return $this->status === ReceiptStatus::Issued;
    }

    /** Попытки кончились — дальше только руками. */
    public function needsAttention(): bool
    {
        return ! $this->isIssued()
            && $this->attempts >= (int) config('fiscal.webkassa.max_attempts');
    }
}
