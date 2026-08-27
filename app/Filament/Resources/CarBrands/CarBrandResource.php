<?php

namespace App\Filament\Resources\CarBrands;

use App\Filament\Concerns\OnlyAdmins;
use App\Filament\Resources\CarBrands\Pages\CreateCarBrand;
use App\Filament\Resources\CarBrands\Pages\EditCarBrand;
use App\Filament\Resources\CarBrands\Pages\ListCarBrands;
use App\Filament\Resources\CarBrands\Schemas\CarBrandForm;
use App\Filament\Resources\CarBrands\Tables\CarBrandsTable;
use App\Models\CarBrand;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CarBrandResource extends Resource
{
    use OnlyAdmins;

    protected static ?string $model = CarBrand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Справочники';

    protected static ?string $navigationLabel = 'Марки';

    protected static ?string $modelLabel = 'марка';

    protected static ?string $pluralModelLabel = 'Марки';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CarBrandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarBrandsTable::configure($table);
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
            'index' => ListCarBrands::route('/'),
            'create' => CreateCarBrand::route('/create'),
            'edit' => EditCarBrand::route('/{record}/edit'),
        ];
    }
}
