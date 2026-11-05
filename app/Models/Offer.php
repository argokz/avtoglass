<?php

namespace App\Models;

use App\Catalog\StockLedger;
use App\Domain\Shared\Casts\ZonedTimestamp;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Once;

class Offer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        $flush = static fn () => Once::flush();
        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'price_wholesale' => 'decimal:2',
            'price_retail' => 'decimal:2',
            'is_active' => 'boolean',
            'price_updated_at' => 'datetime',
            'auto_checkout_enabled' => 'boolean',
            'stock_verified_at' => ZonedTimestamp::class,
            'price_valid_until' => ZonedTimestamp::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * Сколько единиц можно продать прямо сейчас.
     *
     * Именно это число показывается клиенту: stock_qty — то, что лежит на
     * полке, включая уже обещанное другим заказам.
     */
    public function available(): int
    {
        return app(StockLedger::class)->available($this);
    }

    /**
     * Розница из оптовой цены по наценке поставщика.
     *
     * Наценка по умолчанию нулевая: витрина показывает ровно ту цену, что
     * стоит в прайсе. Проценты задаются в карточке поставщика — когда там
     * появятся настоящие правила, цены пересчитаются сами.
     */
    public function calculateRetail(): ?float
    {
        if ($this->price_wholesale === null) {
            return null;
        }

        $markup = (float) ($this->supplier?->markup_percent ?? 0);
        $wholesale = (float) $this->price_wholesale;

        // Без наценки округлять нечего: цена и есть прайсовая.
        if ($markup <= 0) {
            return $wholesale;
        }

        return round($wholesale * (1 + $markup / 100), -1);
    }

    /**
     * Цена для конкретного покупателя.
     *
     * Закуп (price_wholesale) — себестоимость, её на витрину не отдаём.
     * Опту считаем розницу минус скидка менеджера, но не дешевле закупа.
     */
    public function sellingPrice(?Customer $buyer = null): ?float
    {
        if ($this->price_retail === null) {
            return null;
        }

        $retail = (float) $this->price_retail;

        if ($retail <= 0) {
            return null;
        }

        if ($buyer?->seesWholesalePrices() !== true) {
            return $retail;
        }

        return $buyer->wholesalePrice($retail, $this->price_wholesale !== null ? (float) $this->price_wholesale : null);
    }
}
