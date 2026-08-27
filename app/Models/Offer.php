<?php

namespace App\Models;

use App\Catalog\StockLedger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_wholesale' => 'decimal:2',
            'price_retail' => 'decimal:2',
            'is_active' => 'boolean',
            'price_updated_at' => 'datetime',
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

    /** Розница из оптовой цены по наценке поставщика. */
    public function calculateRetail(): ?float
    {
        if ($this->price_wholesale === null) {
            return null;
        }

        $markup = (float) ($this->supplier?->markup_percent ?? 30);

        return round((float) $this->price_wholesale * (1 + $markup / 100), -1);
    }
}
