<?php

namespace App\Models;

use App\Enums\GlassType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;

/**
 * Работа: установка, калибровка, снятие-постановка.
 *
 * Цена живёт здесь, а не в карточке товара: работа одна на весь тип стекла,
 * а карточек этого типа тысячи. Менеджер меняет цену установки в одном месте,
 * и она сразу становится верной на всей витрине.
 */
class Service extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'duration_minutes' => 60,
        'is_default' => false,
        'is_active' => true,
        'sort' => 500,
    ];

    protected static function booted(): void
    {
        $flush = static fn () => Once::flush();
        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'glass_type' => GlassType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Все активные работы одним запросом на HTTP-запрос.
     *
     * В корзине и карточке товара прайс работ спрашивают по несколько раз
     * подряд (для разных способов получения и калибровки).
     *
     * @return Collection<int, self>
     */
    public static function activeCatalog(): Collection
    {
        return once(fn () => static::query()
            ->active()
            ->orderBy('sort')
            ->orderBy('price')
            ->get());
    }

    /**
     * Длительность словами: «2 ч», «1 ч 30 мин», «45 мин».
     *
     * Покупателю нужно понять, уедет он сегодня или оставит машину, а «120»
     * в графе «минут» этого не сообщает — он считает в уме.
     */
    public function humanDuration(): ?string
    {
        $minutes = (int) $this->duration_minutes;

        if ($minutes <= 0) {
            return null;
        }

        if ($minutes < 60) {
            return $minutes.' мин';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.' ч' : $hours.' ч '.$rest.' мин';
    }

    /**
     * Работы, применимые к этому стеклу.
     *
     * Работа без указанного типа стекла подходит к любому — так задают выезд
     * мастера или снятие-постановку, которые от вида стекла не зависят.
     */
    public function scopeForGlass(Builder $query, ?GlassType $type): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q
                ->whereNull('glass_type')
                ->when($type, fn (Builder $inner) => $inner->orWhere('glass_type', $type->value)))
            ->orderBy('sort')
            ->orderBy('price');
    }

    /**
     * Установка, которую предлагаем к этому стеклу по умолчанию.
     *
     * Одна и только одна: витрина показывает «плюс установка N ₸», и выбор
     * из трёх вариантов на этом месте покупателю не нужен — остальное
     * досогласует менеджер.
     */
    public static function defaultFor(?GlassType $type): ?self
    {
        return static::activeCatalog()
            ->filter(fn (self $s) => $s->is_default && ($s->glass_type === null || ($type !== null && $s->glass_type === $type)))
            // Точное совпадение по типу важнее общей работы «на любое стекло»
            ->sortBy(fn (self $s) => [
                $s->glass_type === null ? 1 : 0,
                (int) $s->sort,
                (float) $s->price,
            ])
            ->first();
    }

    /** Как строка попадёт в заказ и в акт выполненных работ. */
    public function toOrderItem(): array
    {
        return [
            'name' => $this->name,
            'price' => $this->price,
            'quantity' => 1,
        ];
    }

    /** Выезд мастера — отдельная работа, не установка. */
    public static function visitFee(): ?self
    {
        return static::activeCatalog()
            ->first(fn (self $s) => $s->slug === 'vyezd-mastera');
    }

    /** Калибровка камеры ADAS после замены лобового стекла. */
    public static function calibration(): ?self
    {
        return static::activeCatalog()
            ->first(fn (self $s) => $s->slug === 'kalibrovka-adas');
    }
}
