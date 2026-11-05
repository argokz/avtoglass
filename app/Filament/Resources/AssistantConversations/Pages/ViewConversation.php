<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantConversations\Pages;

use App\Filament\Resources\AssistantConversations\AssistantConversationResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewConversation extends ViewRecord
{
    protected static string $resource = AssistantConversationResource::class;
}
