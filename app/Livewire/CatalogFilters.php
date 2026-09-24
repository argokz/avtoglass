<?php

namespace App\Livewire;

use App\Catalog\ModelYears;
use App\Catalog\ProductFilter;
use App\Catalog\ProductQuery;
use App\Catalog\ProductSort;
use App\Enums\GlassType;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Подбор стекла на странице модели.
 *
 * Раньше каждый фильтр был обычной ссылкой и перезагружал страницу целиком:
 * экран моргал, прокрутка прыгала наверх, и выбор года ощущался как переход
 * на другой сайт. Здесь меняется только выдача.
 *
 * При этом ничего не потеряно для поиска: состояние фильтров живёт в адресной
 * строке через #[Url], первый ответ сервера — готовый HTML, а сами фильтры
 * остались ссылками с настоящим href. Google обойдёт их как раньше, а у
 * человека клик обрабатывается без перезагрузки.
 */
class CatalogFilters extends Component
{
    use WithPagination;

    public CarBrand $brand;

    public CarModel $model;

    /*
     * Значения по умолчанию в адрес не пишем (except): ссылка на страницу
     * модели должна оставаться короткой и одинаковой, иначе поисковик
     * увидит десяток адресов с одним и тем же содержимым.
     *
     * history не включаем сознательно. В Livewire 4.4 режим push вешает
     * обработчик на коммит только для компонентов, пришедших ответом сервера,
     * — у страницы, отрисованной сразу, адрес не обновляется вовсе. Режим
     * replace работает через реактивность и обновляет адрес всегда.
     * Плата: «назад» уводит со страницы, а не отматывает фильтры по одному.
     * Рабочий адрес важнее шага истории — ссылкой на выдачу делятся,
     * по истории фильтров не ходят.
     */
    #[Url(except: '')]
    public string $type = '';

    /** Строкой, а не числом: у пустого пункта select значение '' — не 0. */
    #[Url(except: '')]
    public string $year = '';

    #[Url(as: 'in_stock', except: false)]
    public bool $inStock = false;

    #[Url(except: 'price_asc')]
    public string $sort = 'price_asc';

    /** Марка самого стекла: AGC, КМК, XYG. В базе это поставщик. */
    #[Url(as: 'brand', except: 0)]
    public int $supplierId = 0;

    #[Url(except: false)]
    public bool $camera = false;

    #[Url(except: false)]
    public bool $rain_sensor = false;

    #[Url(except: false)]
    public bool $sun_band = false;

    #[Url(except: false)]
    public bool $heated_wipers = false;

    #[Url(except: false)]
    public bool $heated_full = false;

    /**
     * Любая смена фильтра возвращает на первую страницу.
     *
     * Иначе человек, стоящий на четвёртой странице, выберет год и попадёт
     * на пустую четвёртую страницу нового, более короткого списка — и решит,
     * что стекла нет.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    /** Сброс всех фильтров разом — кнопка «показать все стёкла». */
    public function resetFilters(): void
    {
        $this->type = '';
        $this->year = '';
        $this->inStock = false;
        $this->supplierId = 0;
        $this->camera = false;
        $this->rain_sensor = false;
        $this->sun_band = false;
        $this->heated_wipers = false;
        $this->heated_full = false;
        $this->resetPage();
    }

    public function toggleStock(): void
    {
        $this->inStock = ! $this->inStock;
        $this->resetPage();
    }

    public function selectType(string $type): void
    {
        $this->type = $this->type === $type ? '' : $type;
        $this->resetPage();
    }

    public function toggleOption(string $flag): void
    {
        if (! array_key_exists($flag, Product::STOREFRONT_OPTIONS)) {
            return;
        }

        $this->{$flag} = ! $this->{$flag};
        $this->resetPage();
    }

    private function filter(): ProductFilter
    {
        return new ProductFilter(
            type: GlassType::tryFrom($this->type),
            options: $this->selectedOptions(),
            carModelId: $this->model->getKey(),
            // Год чистим тем же правилом, что и при заходе по прямой ссылке:
            // в адресной строке может оказаться что угодно.
            year: settings()->enabled('year_filter') ? ProductFilter::sanitizeYear($this->year) : null,
            sort: ProductSort::tryFrom($this->sort) ?? ProductSort::PriceAsc,
            inStockOnly: $this->inStock,
            supplierId: $this->supplierId ?: null,
        );
    }

    /** @return list<string> */
    private function selectedOptions(): array
    {
        $selected = [];

        foreach (array_keys(Product::STOREFRONT_OPTIONS) as $flag) {
            if ($this->{$flag}) {
                $selected[] = $flag;
            }
        }

        return $selected;
    }

    public function render(): View
    {
        $filter = $this->filter();
        $products = app(ProductQuery::class);
        $years = app(ModelYears::class);

        return view('livewire.catalog-filters', [
            'filter' => $filter,
            'products' => $products->paginate($filter),
            'types' => $products->countsByType($filter),
            'suppliers' => $products->countsBySupplier($filter),
            'optionCounts' => $products->countsByOption($filter),
            'years' => $years->for($this->model),
            'generation' => $years->generationFor($this->model, $filter->year),
        ]);
    }
}
