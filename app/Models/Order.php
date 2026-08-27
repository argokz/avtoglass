<?php

namespace App\Models;

use App\Catalog\StockLedger;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
            'fulfillment' => FulfillmentType::class,
            'total_amount' => 'decimal:2',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class)->latest('occurred_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /** Заказ считается оплаченным, если хоть одна попытка прошла успешно. */
    public function isPaid(): bool
    {
        return $this->payments()->where('status', PaymentStatus::Succeeded)->exists();
    }

    /**
     * Сколько ещё не заплачено. Считается от суммы заказа минус все успешные
     * платежи — и карточные, и наличные: клиент мог внести часть онлайн,
     * а остаток отдать курьеру.
     */
    public function outstanding(): float
    {
        return max(0, round((float) $this->total_amount - $this->paidAmount(), 2));
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()
            ->where('status', PaymentStatus::Succeeded)
            ->sum('amount');
    }

    /**
     * Номер выдаётся после вставки, из настоящего id.
     *
     * Считать его как «максимальный плюс один» нельзя: два заказа, оформленных
     * в одну секунду, получили бы одинаковый номер — сначала в тестах, а потом
     * и у двух живых клиентов.
     */
    protected static function booted(): void
    {
        static::created(function (self $order) {
            if ($order->number === null) {
                $order->forceFill(['number' => self::formatNumber($order->id)])->saveQuietly();
            }
        });
    }

    /** Клиент называет номер по телефону, поэтому он короткий, а не UUID. */
    public static function formatNumber(int $id): string
    {
        return 'АС-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Перевод в другой статус с проверкой маршрута.
     * Возврат из «Выполнен» в «Новая» — это не сценарий, а ошибка,
     * и молча её пропускать нельзя.
     */
    public function transitionTo(OrderStatus $target): bool
    {
        if (! $this->status->canTransitionTo($target)) {
            return false;
        }

        $from = $this->status;
        $this->status = $target;

        if ($target === OrderStatus::Completed) {
            $this->completed_at = now();
        }

        $saved = $this->save();

        if ($saved) {
            // Журнал пишем здесь, а не в контроллере: статус меняют из списка,
            // из карточки, из планировщика и из консоли — в каждом месте
            // об этом забудут по-своему.
            Interaction::system($this, "Статус: {$from->label()} -> {$target->label()}");

            $this->syncStock($target);
        }

        return $saved;
    }

    /**
     * Склад ходит следом за статусом.
     *
     * Здесь же, по той же причине, что и журнал: заказ подтверждают из списка,
     * из карточки, колбэком кассы и приёмом наличных. Развесь мы резерв по этим
     * четырём местам — в пятом о нём забудут, и «зарезервировано за вами»
     * снова станет надписью.
     */
    private function syncStock(OrderStatus $target): void
    {
        $ledger = app(StockLedger::class);

        match ($target) {
            OrderStatus::Confirmed, OrderStatus::Scheduled => $ledger->reserve($this),
            OrderStatus::Cancelled => $ledger->release($this),
            OrderStatus::Completed => $ledger->consume($this),
            default => null,
        };
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** Сумма по позициям. Работы сюда не входят — их считает менеджер. */
    public function recalculateTotal(): void
    {
        $total = $this->items->sum(fn (OrderItem $item) => (float) $item->price * $item->quantity);

        $this->forceFill(['total_amount' => $total ?: null])->save();
    }
}
