<?php

namespace App\Filament\Resources\Services;

use App\Filament\Concerns\OnlyAdmins;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Прайс на работы.
 *
 * Цены на работу меняются чаще, чем на стекло, и ходить за этим к программисту
 * нельзя. Одна правка здесь меняет сумму на всей витрине: работа привязана
 * к типу стекла, а не к карточке.
 */
class ServiceResource extends Resource
{
    use OnlyAdmins;

    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static UnitEnum|string|null $navigationGroup = 'Каталог';

    protected static ?string $navigationLabel = 'Работы и цены';

    protected static ?string $modelLabel = 'работа';

    protected static ?string $pluralModelLabel = 'Работы';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
