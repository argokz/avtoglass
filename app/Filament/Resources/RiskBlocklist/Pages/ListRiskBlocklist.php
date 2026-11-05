<?php

namespace App\Filament\Resources\RiskBlocklist\Pages;

use App\Filament\Resources\RiskBlocklist\RiskBlocklistResource;
use App\Models\RiskBlocklistEntry;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRiskBlocklist extends ListRecords
{
    protected static string $resource = RiskBlocklistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    // В базу уходит хэш, а не введённый номер или адрес.
                    $data['value'] = RiskBlocklistEntry::hashFor($data['type'], (string) $data['value']);

                    return $data;
                }),
        ];
    }
}
