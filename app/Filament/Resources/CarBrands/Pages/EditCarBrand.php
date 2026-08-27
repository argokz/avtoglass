<?php

namespace App\Filament\Resources\CarBrands\Pages;

use App\Filament\Resources\CarBrands\CarBrandResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCarBrand extends EditRecord
{
    protected static string $resource = CarBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
