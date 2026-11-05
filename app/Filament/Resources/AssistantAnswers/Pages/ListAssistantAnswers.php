<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantAnswers\Pages;

use App\Filament\Resources\AssistantAnswers\AssistantAnswerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAssistantAnswers extends ListRecords
{
    protected static string $resource = AssistantAnswerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
