<?php

namespace App\Catalog;

use App\Enums\GlassType;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Набор фильтров каталога, отделённый от источника.
 *
 * Витрине он приходит из query-строки, Livewire-компоненту — из свойств,
 * админке — из формы. Логика отбора не должна знать, откуда взялись значения,
 * поэтому она работает с этим объектом, а не с Request.
 *
 * @phpstan-type OptionFlag key-of<Product::OPTION_FLAGS>
 */
readonly class ProductFilter
{
    /**
     * Год, раньше которого машин в каталоге не бывает.
     * Всё, что пришло меньше, — мусор из адресной строки, а не подбор.
     */
    private const EARLIEST_YEAR = 1950;

    /**
     * @param  array<int,string>  $options  включённые опции (sun_band, camera, ...)
     */
    public function __construct(
        public ?GlassType $type = null,
        public array $options = [],
        public ?int $carModelId = null,
        public ?int $year = null,
        public ?string $query = null,
        public ProductSort $sort = ProductSort::PriceAsc,
        public bool $inStockOnly = false,
    ) {}

    public static function fromRequest(Request $request, ?int $carModelId = null): self
    {
        $options = array_values(array_filter(
            array_keys(Product::OPTION_FLAGS),
            fn (string $flag) => $request->boolean($flag),
        ));

        return new self(
            type: GlassType::tryFrom((string) $request->query('type')),
            options: $options,
            carModelId: $carModelId,
            year: self::cleanYear($request->query('year')),
            query: self::cleanQuery($request->query('q')),
            sort: ProductSort::tryFrom((string) $request->query('sort')) ?? ProductSort::PriceAsc,
            inStockOnly: $request->boolean('in_stock'),
        );
    }

    /** Пустой фильтр — признак того, что показывать нечего, а не «показать всё». */
    public function isSearch(): bool
    {
        return $this->query !== null;
    }

    public function hasAnyFilter(): bool
    {
        return $this->type !== null || $this->options !== [] || $this->inStockOnly || $this->year !== null;
    }

    /**
     * Текущий фильтр как параметры ссылки.
     *
     * Нужен потому, что фильтров на странице несколько, и каждая ссылка обязана
     * сохранить все остальные: выбрал год, нажал «лобовое» — год не должен
     * потеряться. Раньше каждая ссылка перечисляла параметры руками, и о новом
     * фильтре пришлось бы вспомнить в пяти местах.
     *
     * @param  array<string,mixed>  $overrides  null убирает параметр
     * @return array<string,mixed>
     */
    public function toQuery(array $overrides = []): array
    {
        $params = [
            'type' => $this->type?->value,
            'year' => $this->year,
            // Сортировку по умолчанию в адрес не пишем: незачем делать
            // ссылку длиннее, чем она есть по смыслу.
            'sort' => $this->sort === ProductSort::PriceAsc ? null : $this->sort->value,
            'in_stock' => $this->inStockOnly ? 1 : null,
            'q' => $this->query,
        ];

        foreach ($this->options as $flag) {
            $params[$flag] = 1;
        }

        return array_filter(
            array_merge($params, $overrides),
            fn ($value) => $value !== null
        );
    }

    /**
     * Слишком короткий запрос отбрасываем: на «а» база вернёт весь каталог
     * и положит страницу.
     */
    private static function cleanQuery(mixed $value): ?string
    {
        $value = trim((string) $value);

        return mb_strlen($value) >= 2 ? $value : null;
    }

    /**
     * Год выпуска. Верхняя граница — следующий календарный: новые модели
     * продаются как «2027» уже осенью 2026-го.
     */
    private static function cleanYear(mixed $value): ?int
    {
        $year = (int) $value;

        return $year >= self::EARLIEST_YEAR && $year <= (int) date('Y') + 1
            ? $year
            : null;
    }
}
