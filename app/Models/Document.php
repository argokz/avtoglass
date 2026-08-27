<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Document extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'payload' => 'array',
            'issued_on' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Выдаёт документ или возвращает уже выданный.
     *
     * Повторная печать обязана дать тот же номер: клиент на него уже сослался,
     * а бухгалтерия внесла в учёт. Второй номер на тот же товарный чек —
     * это расхождение в отчётности, а не просто некрасиво.
     */
    public static function issue(
        DocumentType $type,
        ?Order $order = null,
        ?Assignment $assignment = null,
        ?int $userId = null,
        array $payload = [],
    ): self {
        $existing = static::query()
            ->where('type', $type)
            ->when($order, fn ($q) => $q->where('order_id', $order->id))
            ->when($assignment, fn ($q) => $q->where('assignment_id', $assignment->id))
            ->when($order === null && $assignment === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'type' => $type,
            'number' => static::nextNumber($type),
            'order_id' => $order?->id,
            'assignment_id' => $assignment?->id,
            'issued_by' => $userId,
            'issued_on' => today(),
            'payload' => $payload,
        ]);
    }

    /**
     * Номер вида «ТЧ-2026-000012»: свой счётчик на тип и год.
     *
     * Сквозная нумерация по всем типам сразу выглядела бы у бухгалтера как
     * дыры в журнале: между двумя счетами лежали бы гарантийные талоны.
     */
    public static function nextNumber(DocumentType $type): string
    {
        $year = now()->year;

        return DB::transaction(function () use ($type, $year) {
            $last = static::query()
                ->where('type', $type)
                ->whereYear('issued_on', $year)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('number');

            $sequence = $last ? ((int) substr($last, -6)) + 1 : 1;

            return sprintf('%s-%d-%06d', $type->prefix(), $year, $sequence);
        });
    }
}
