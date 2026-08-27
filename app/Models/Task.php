<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Open);
    }

    /**
     * Просроченные — то, ради чего список задач вообще открывают.
     * Закрытая задача просроченной не бывает, как бы поздно её ни закрыли.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->where('due_at', '<', now());
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->open()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_at->isPast();
    }

    public function complete(?string $result = null): bool
    {
        if (! $this->status->isOpen()) {
            return false;
        }

        return $this->forceFill([
            'status' => TaskStatus::Done,
            'completed_at' => now(),
            'result' => $result,
        ])->save();
    }

    public function cancel(?string $reason = null): bool
    {
        if (! $this->status->isOpen()) {
            return false;
        }

        return $this->forceFill([
            'status' => TaskStatus::Cancelled,
            'completed_at' => now(),
            'result' => $reason,
        ])->save();
    }
}
