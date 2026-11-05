<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantConversations\Pages;

use App\Filament\Resources\AssistantConversations\AssistantConversationResource;
use Filament\Resources\Pages\ListRecords;

final class ListConversations extends ListRecords
{
    protected static string $resource = AssistantConversationResource::class;
}
