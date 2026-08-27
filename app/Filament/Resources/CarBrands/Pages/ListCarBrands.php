<?php

namespace App\Filament\Resources\CarBrands\Pages;

use App\Filament\Resources\CarBrands\CarBrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarBrands extends ListRecords
{
    protected static string $resource = CarBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
