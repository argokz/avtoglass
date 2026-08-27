<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\GlassType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->helperText('Так работа попадёт в заказ, в чек и в акт выполненных работ'),

                TextInput::make('slug')
                    ->label('Код')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('Служебный, менять не нужно'),

                TextInput::make('price')
                    ->label('Цена, ₸')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Меняется здесь и сразу становится верной на всей витрине'),

                Select::make('glass_type')
                    ->label('К какому стеклу')
                    ->options(collect(GlassType::cases())
                        ->mapWithKeys(fn (GlassType $t) => [$t->value => $t->label()]))
                    ->placeholder('К любому')
                    ->helperText('Пусто — работа не зависит от стекла: выезд мастера, снятие-постановка'),

                TextInput::make('duration_minutes')
                    ->label('Занимает, минут')
                    ->required()
                    ->numeric()
                    ->minValue(5)
                    ->default(60)
                    ->helperText('По этому планировщик подбирает окно в смене'),

                TextInput::make('sort')
                    ->label('Порядок')
                    ->required()
                    ->numeric()
                    ->default(500),

                /*
                 * Разделение важное: калибровка ADAS в прайсе быть обязана,
                 * но предлагать её всем нельзя — она нужна только там, где
                 * за стеклом камера, и решает это мастер после осмотра.
                 */
                Toggle::make('is_default')
                    ->label('Предлагать на витрине')
                    ->helperText('Показывать как «плюс установка» рядом с ценой стекла'),

                Toggle::make('is_active')
                    ->label('Делаем')
                    ->default(true),

                Textarea::make('description')
                    ->label('Что входит')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Видно покупателю и попадает в акт'),
            ]);
    }
}
