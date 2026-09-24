<?php

namespace App\Filament\Resources\CarModels;

use App\Filament\Concerns\OnlyAdmins;
use App\Filament\Resources\CarModels\Pages\CreateCarModel;
use App\Filament\Resources\CarModels\Pages\EditCarModel;
use App\Filament\Resources\CarModels\Pages\ListCarModels;
use App\Filament\Resources\CarModels\Schemas\CarModelForm;
use App\Filament\Resources\CarModels\Tables\CarModelsTable;
use App\Models\CarModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CarModelResource extends Resource
{
    use OnlyAdmins;

    protected static ?string $model = CarModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Справочники';

    protected static ?string $navigationLabel = 'Модели';

    protected static ?string $modelLabel = 'модель';

    protected static ?string $pluralModelLabel = 'Модели';

    protected static ?int $navigationSort = 2;

    /**
     * Счётчик показывает очередь разбора, а не число моделей: в имени остался
     * хвост от прайса, и покупатель видит его в подборе. Правит человек —
     * верное имя знает он, а не эвристика.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('needs_review', true)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return CarModelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarModelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarModels::route('/'),
            'create' => CreateCarModel::route('/create'),
            'edit' => EditCarModel::route('/{record}/edit'),
        ];
    }
}
