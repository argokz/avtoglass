<?php

namespace App\Filament\Resources\CarModelAliases\Pages;

use App\Filament\Resources\CarModelAliases\CarModelAliasResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCarModelAlias extends EditRecord
{
    protected static string $resource = CarModelAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
