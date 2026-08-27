<?php

namespace App\Filament\Resources\ServicePoints;

use App\Filament\Concerns\OnlyAdmins;
use App\Filament\Resources\ServicePoints\Pages\CreateServicePoint;
use App\Filament\Resources\ServicePoints\Pages\EditServicePoint;
use App\Filament\Resources\ServicePoints\Pages\ListServicePoints;
use App\Filament\Resources\ServicePoints\Schemas\ServicePointForm;
use App\Filament\Resources\ServicePoints\Tables\ServicePointsTable;
use App\Models\ServicePoint;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ServicePointResource extends Resource
{
    use OnlyAdmins;

    protected static ?string $model = ServicePoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Логистика';

    protected static ?string $navigationLabel = 'Сервисные точки';

    protected static ?string $modelLabel = 'сервисная точка';

    protected static ?string $pluralModelLabel = 'Сервисные точки';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ServicePointForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicePointsTable::configure($table);
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
            'index' => ListServicePoints::route('/'),
            'create' => CreateServicePoint::route('/create'),
            'edit' => EditServicePoint::route('/{record}/edit'),
        ];
    }
}
