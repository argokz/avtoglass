<?php

namespace App\Filament\Resources\RiskBlocklist;

use App\Filament\Concerns\OnlyAdmins;
use App\Filament\Resources\RiskBlocklist\Pages\ListRiskBlocklist;
use App\Filament\Resources\RiskBlocklist\Schemas\RiskBlocklistForm;
use App\Filament\Resources\RiskBlocklist\Tables\RiskBlocklistTable;
use App\Models\RiskBlocklistEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RiskBlocklistResource extends Resource
{
    use OnlyAdmins;

    protected static ?string $model = RiskBlocklistEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static UnitEnum|string|null $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Стоп-лист';

    protected static ?string $modelLabel = 'запись стоп-листа';

    protected static ?string $pluralModelLabel = 'Стоп-лист антифрода';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return RiskBlocklistForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RiskBlocklistTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRiskBlocklist::route('/'),
        ];
    }
}
