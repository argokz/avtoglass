<?php

namespace App\Filament\Resources\ServicePoints\Pages;

use App\Filament\Resources\ServicePoints\ServicePointResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServicePoint extends EditRecord
{
    protected static string $resource = ServicePointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
