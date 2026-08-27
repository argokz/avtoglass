<?php

namespace App\Filament\Resources\CarModelAliases\Pages;

use App\Filament\Resources\CarModelAliases\CarModelAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarModelAliases extends ListRecords
{
    protected static string $resource = CarModelAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
