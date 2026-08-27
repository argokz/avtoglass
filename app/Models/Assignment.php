<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Assignment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => AssignmentType::class,
            'status' => AssignmentStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(ServicePoint::class);
    }

    public function endsAt(): Carbon
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes);
    }

    /** Наряды исполнителя на конкретный день, в порядке маршрута. */
    public function scopeForDay(Builder $query, int $userId, Carbon $day): Builder
    {
        return $query->where('assignee_id', $userId)
            ->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('sequence')
            ->orderBy('scheduled_at');
    }

    /** Живые наряды — те, по которым ещё что-то предстоит сделать. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            AssignmentStatus::Done->value,
            AssignmentStatus::Failed->value,
        ]);
    }

    /**
     * Пересекается ли по времени с другим отрезком.
     * Касание границами пересечением не считается: наряд с 10:00 до 12:00
     * и следующий с 12:00 живут мирно.
     */
    public function overlaps(Carbon $start, Carbon $end): bool
    {
        return $this->scheduled_at->lt($end) && $this->endsAt()->gt($start);
    }

    public function transitionTo(AssignmentStatus $target, ?string $reason = null): bool
    {
        if (! $this->status->canTransitionTo($target, $this->type)) {
            return false;
        }

        $this->status = $target;

        if ($target === AssignmentStatus::InProgress && $this->started_at === null) {
            $this->started_at = now();
        }

        if ($target->isFinal()) {
            $this->finished_at = now();
        }

        if ($target === AssignmentStatus::Failed) {
            $this->failure_reason = $reason;
        }

        return $this->save();
    }
}
