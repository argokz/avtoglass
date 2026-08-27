<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\InteractionType;
use App\Models\Interaction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * История общения по заявке.
 *
 * Системные записи здесь тоже видны — именно из них складывается ответ на
 * вопрос «что вообще происходило». Но править и удалять их нельзя: журнал,
 * который можно переписать, перестаёт быть журналом.
 */
class InteractionsRelationManager extends RelationManager
{
    protected static string $relationship = 'interactions';

    protected static ?string $title = 'История';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Что было')
                ->options(InteractionType::manualOptions())
                ->default(InteractionType::CallOut->value)
                ->required(),

            DateTimePicker::make('occurred_at')
                ->label('Когда')
                ->seconds(false)
                ->default(now())
                ->required()
                // Звонок мог быть вчера, а записать про него вспомнили сегодня
                ->helperText('Время самого события, а не момента записи'),

            Textarea::make('body')->label('О чём')->rows(4)->required()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->icon(fn (InteractionType $state) => $state->icon())
                    ->formatStateUsing(fn (InteractionType $state) => $state->label())
                    ->color(fn (InteractionType $state) => $state->color()),

                TextColumn::make('body')->label('Содержание')->wrap(),

                TextColumn::make('author.name')->label('Кто')->placeholder('система'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить запись')
                    ->mutateDataUsing(function (array $data): array {
                        $data['user_id'] = Auth::id();
                        $data['customer_id'] = $this->getOwnerRecord()->customer_id;

                        return $data;
                    }),
            ])
            ->recordActions([
                // Системные записи неприкосновенны
                EditAction::make()->visible(fn (Interaction $r) => ! $r->type->isSystem()),
                DeleteAction::make()->visible(fn (Interaction $r) => ! $r->type->isSystem()),
            ])
            ->emptyStateHeading('Записей пока нет');
    }
}
