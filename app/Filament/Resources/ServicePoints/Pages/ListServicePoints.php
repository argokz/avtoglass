<?php

namespace App\Filament\Resources\ServicePoints\Pages;

use App\Filament\Resources\ServicePoints\ServicePointResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServicePoints extends ListRecords
{
    protected static string $resource = ServicePointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
