<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\GlassType;
use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Карточка')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Наименование')
                        ->required()
                        ->columnSpanFull(),

                    Select::make('glass_type')
                        ->label('Тип стекла')
                        ->options(collect(GlassType::cases())
                            ->mapWithKeys(fn (GlassType $t) => [$t->value => $t->label()]))
                        ->required(),

                    Select::make('position')
                        ->label('Сторона')
                        ->options(['left' => 'Левое', 'right' => 'Правое'])
                        ->helperText('Для бокового стекла сторона обязательна — левое и правое это разные товары'),

                    Textarea::make('description')->label('Описание')->columnSpanFull(),

                    /*
                     * Фотография одна и не обязательна.
                     *
                     * Стёкла в прайсах приходят без картинок, и ждать, пока
                     * снимут все пять тысяч позиций, значит не показать ни одной.
                     * Где фото нет — карточка рисует схематичный значок типа
                     * стекла, и это лучше пустого прямоугольника.
                     */
                    FileUpload::make('image_path')
                        ->label('Фотография')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('products')
                        ->maxSize(4096)
                        ->helperText('До 4 МБ. Снимок самого стекла, без фона и людей')
                        ->columnSpanFull(),
                ]),

            Section::make('Опции')
                ->description('По ним работают фильтры каталога и мастер подбора')
                ->columns(3)
                ->schema(
                    collect(Product::OPTION_FLAGS)
                        ->map(fn (string $label, string $flag) => Toggle::make($flag)->label($label))
                        ->values()
                        ->all()
                ),

            Section::make('Коды и размеры')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextInput::make('eurocode')->label('Еврокод')->helperText('Маркировка на самом стекле'),
                    TextInput::make('oem_code')->label('Код OEM'),
                    TextInput::make('internal_code')->label('Внутренний артикул'),
                    TextInput::make('width_mm')->label('Ширина, мм')->numeric(),
                    TextInput::make('height_mm')->label('Высота, мм')->numeric(),
                    Select::make('tint')
                        ->label('Тонировка')
                        ->options([
                            'green' => 'Зелёная',
                            'grey' => 'Серая',
                            'blue' => 'Голубая',
                            'bronze' => 'Бронзовая',
                            'clear' => 'Без тонировки',
                            'tinted' => 'Тонированное',
                        ]),
                ]),

            Section::make('Витрина')
                ->columns(2)
                ->schema([
                    Toggle::make('is_published')
                        ->label('Показывать покупателям')
                        ->helperText('Карточка без цены в витрину не попадёт даже с этим переключателем'),

                    Toggle::make('needs_review')->label('Требует проверки'),

                    // Цены считаются из предложений поставщиков, руками их не правят:
                    // следующая сборка каталога всё равно перезапишет значение.
                    TextInput::make('price_min')
                        ->label('Цена от')
                        ->disabled()
                        ->helperText('Считается автоматически по предложениям поставщиков'),

                    TextInput::make('price_max')->label('Цена до')->disabled(),
                ]),
        ]);
    }
}
