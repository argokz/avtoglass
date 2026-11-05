<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantConversations;

use App\Ai\Runtime\Conversation;
use App\Ai\Runtime\MessageText;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

final class AssistantConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static ?string $slug = 'assistant-conversations';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static UnitEnum|string|null $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Диалоги помощника';

    public static function canViewAny(): bool
    {
        return Auth::user()?->role->canWorkWithOrders() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->label('Диалог')->wrap(), TextColumn::make('locale')->label('Язык'),
            TextColumn::make('updated_at')->label('Обновлён')->dateTime()])->defaultSort('updated_at', 'desc')->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->label('Диалог'),
            TextEntry::make('transcript')->label('Последние 100 сообщений')->getStateUsing(fn (Conversation $record) => DB::table('ai_messages')->where('conversation_id', $record->id)->latest('id')->limit(100)->get()->reverse()
                ->map(fn ($message) => ($message->role === 'user' ? 'Покупатель: ' : 'Помощник: ').MessageText::decode($message->text))->implode("\n\n"))->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListConversations::route('/'), 'view' => Pages\ViewConversation::route('/{record}')];
    }
}
