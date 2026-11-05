<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantAnswers;

use App\Filament\Concerns\OnlyAdmins;
use App\Models\AssistantAnswer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class AssistantAnswerResource extends Resource
{
    use OnlyAdmins;

    protected static ?string $model = AssistantAnswer::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static UnitEnum|string|null $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Ответы помощника';

    protected static ?string $modelLabel = 'ответ помощника';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('topic')->label('Тема')->options(['delivery' => 'Доставка', 'installation' => 'Установка', 'warranty' => 'Гарантия', 'payment' => 'Оплата'])->required(),
            Select::make('locale')->label('Язык')->options(['ru' => 'Русский', 'kk' => 'Қазақша'])->required(),
            TextInput::make('title')->label('Заголовок')->required()->maxLength(190),
            Textarea::make('answer')->label('Утверждённый ответ')->required()->maxLength(4000)->rows(6),
            TextInput::make('source')->label('Основание / источник')->required()->maxLength(190),
            DateTimePicker::make('valid_until')->label('Действует до'),
            Toggle::make('is_published')->label('Утверждён и опубликован'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->label('Ответ'), TextColumn::make('locale')->label('Язык'), IconColumn::make('is_published')->label('Опубликован')->boolean(), TextColumn::make('valid_until')->label('До')->dateTime()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAssistantAnswers::route('/')];
    }
}
