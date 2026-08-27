<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\CarBrand;
use App\Models\Product;
use App\Models\Supplier;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Чего в каталоге не хватает.
 *
 * Часть данных достаётся из прайсов сама, часть — нет: фотографий там нет
 * вовсе, страну завода не пишут, габариты встречаются в полутора процентах
 * названий. Раз заполнять это человеку, он должен видеть объём, а не узнавать
 * о дырах от покупателя.
 *
 * Каждая цифра — ссылка на отфильтрованный список, чтобы от «не хватает»
 * до «правлю» был один клик.
 */
class CatalogGaps extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    /** Справочники ведёт администратор — менеджеру этот виджет не нужен. */
    public static function canView(): bool
    {
        return Auth::user()?->role->canManageCatalog() ?? false;
    }

    protected function getStats(): array
    {
        $published = Product::where('is_published', true);

        $withoutPhoto = (clone $published)->whereNull('image_path')->count();
        $withoutSize = (clone $published)->whereNull('width_mm')->count();
        $review = Product::where('needs_review', true)->count();

        $suppliersWithoutCountry = Supplier::where('is_active', true)->whereNull('country')->count();
        $brandsWithoutLogo = CarBrand::where('models_count', '>', 0)->whereNull('logo_path')->count();

        return [
            Stat::make('Карточек без фото', (string) $withoutPhoto)
                ->description($withoutPhoto > 0 ? 'значок вместо снимка' : 'все со снимками')
                ->color($withoutPhoto > 0 ? 'warning' : 'success')
                ->url(ProductResource::getUrl('index', ['tableFilters' => ['photo' => ['value' => '0']]])),

            Stat::make('Без габаритов', (string) $withoutSize)
                ->description('в прайсах размеры пишут редко')
                ->color($withoutSize > 0 ? 'gray' : 'success')
                ->url(ProductResource::getUrl('index', ['tableFilters' => ['dimensions' => ['value' => '0']]])),

            Stat::make('Марок стекла без страны', (string) $suppliersWithoutCountry)
                ->description($suppliersWithoutCountry > 0
                    ? 'покупатель выбирает по ней'
                    : 'все заполнены')
                ->color($suppliersWithoutCountry > 0 ? 'danger' : 'success')
                ->url(SupplierResource::getUrl('index')),

            Stat::make('Марок машин без логотипа', (string) $brandsWithoutLogo)
                ->color($brandsWithoutLogo > 0 ? 'gray' : 'success'),

            Stat::make('Карточек на проверку', (string) $review)
                ->description($review > 0 ? 'разбор не уверен' : 'спорных нет')
                ->color($review > 0 ? 'warning' : 'success'),
        ];
    }
}
