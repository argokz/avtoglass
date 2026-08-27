<?php

namespace App\Filament\Resources\CarModelAliases;

use App\Filament\Concerns\OnlyAdmins;
use App\Filament\Resources\CarModelAliases\Pages\CreateCarModelAlias;
use App\Filament\Resources\CarModelAliases\Pages\EditCarModelAlias;
use App\Filament\Resources\CarModelAliases\Pages\ListCarModelAliases;
use App\Filament\Resources\CarModelAliases\Schemas\CarModelAliasForm;
use App\Filament\Resources\CarModelAliases\Tables\CarModelAliasesTable;
use App\Models\CarModelAlias;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CarModelAliasResource extends Resource
{
    use OnlyAdmins;

    protected static ?string $model = CarModelAlias::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Справочники';

    protected static ?string $navigationLabel = 'Псевдонимы моделей';

    protected static ?string $modelLabel = 'псевдоним';

    protected static ?string $pluralModelLabel = 'Псевдонимы моделей';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return CarModelAliasForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarModelAliasesTable::configure($table);
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
            'index' => ListCarModelAliases::route('/'),
            'create' => CreateCarModelAlias::route('/create'),
            'edit' => EditCarModelAlias::route('/{record}/edit'),
        ];
    }
}
