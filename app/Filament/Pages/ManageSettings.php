<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\OnlyAdmins;
use App\Settings\AppSettings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Тумблеры витрины и продаж.
 *
 * Вынесены из кода, потому что «выключить запись на выходные» не должно
 * ждать выкладки. Менять может только администратор: менеджеру хватит
 * выключить оплату по ошибке в пятницу вечером.
 */
class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use OnlyAdmins;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Настройки';

    protected static ?string $title = 'Настройки';

    protected static UnitEnum|string|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.manage-settings';

    /** @var array<string,mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(AppSettings::class)->formState());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Витрина')
                    ->description('Что покупатель видит на сайте')
                    ->columns(2)
                    ->schema([
                        Toggle::make('booking')
                            ->label('Онлайн-запись')
                            ->helperText('Календарь свободного времени на замену'),
                        Toggle::make('vin_lookup')
                            ->label('Подбор по VIN')
                            ->helperText('Блок на главной и страница /vin'),
                        Toggle::make('whatsapp')
                            ->label('Кнопка WhatsApp')
                            ->helperText('Плавающая кнопка и ссылка в подвале'),
                        Toggle::make('cart')
                            ->label('Корзина')
                            ->helperText('Несколько стёкол одной заявкой'),
                        Toggle::make('year_filter')
                            ->label('Фильтр по году')
                            ->helperText('На странице модели автомобиля'),
                        Toggle::make('service_prices')
                            ->label('Цены на установку')
                            ->helperText('Показывать стоимость работы рядом со стеклом'),
                        Toggle::make('display_stock')
                            ->label('Показывать наличие на витрине')
                            ->helperText('Включайте только когда остатки из прайса или 1С, а не после catalog:stock')
                            ->columnSpanFull(),
                        Toggle::make('privacy_consent')
                            ->label('Согласие на персональные данные')
                            ->helperText('Обязательная галочка на формах заявки')
                            ->columnSpanFull(),
                    ]),

                Section::make('Продажи')
                    ->description('Оплата, склад и касса')
                    ->columns(2)
                    ->schema([
                        Toggle::make('payments')
                            ->label('Онлайн-оплата')
                            ->helperText('Кнопка «оплатить картой» после заявки'),
                        Toggle::make('cash_in_field')
                            ->label('Наличные в полевой панели')
                            ->helperText('Курьер и мастер принимают оплату на месте'),
                        Toggle::make('reservations')
                            ->label('Резерв стекла')
                            ->helperText('Держать свободный остаток под подтверждённый заказ'),
                        Toggle::make('fiscal')
                            ->label('Фискальные чеки')
                            ->helperText('Выбивать чек в ОФД после оплаты. Нужны ключи Webkassa.'),
                    ]),

                Section::make('Тарифы доставки для оформления')
                    ->description('Явный тариф и допустимые поставщики. Без подтверждённого правила доставку согласует менеджер.')
                    ->schema([
                        \Filament\Forms\Components\Repeater::make('delivery_policies')->label('Правила доставки')->schema([
                            TextInput::make('city')->label('Город')->required()->maxLength(120),
                            TextInput::make('fee')->label('Доставка, ₸')->required()->regex('/^\d{1,9}([.,]\d{1,2})?$/')->helperText('0 означает явно бесплатную доставку'),
                            \Filament\Forms\Components\Select::make('supplier_ids')->label('Поставщики')->multiple()->searchable()->options(fn () => \App\Models\Supplier::query()->where('is_active', true)->pluck('name', 'id')->all())->required(),
                            TextInput::make('source')->label('Основание тарифа')->required()->maxLength(190),
                            Toggle::make('verified')->label('Тариф проверен и действует'),
                        ])->default([])->maxItems(100),
                    ]),
                Section::make('Опт')
                    ->description('Пороги оптовой заявки. 0 — без ограничений, любая заявка проходит.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('wholesale_min_total')
                            ->label('Минимальная сумма, ₸')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('0 — любая сумма'),
                        TextInput::make('wholesale_min_qty')
                            ->label('Минимальное количество, шт')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('0 — любое количество'),
                    ]),

                Section::make('AI-помощник')
                    ->description('Помощник подбирает стекло и готовит заявку для подтверждения. Бесплатный Gemini или локальная модель LiteLLM выбираются через AI_PROVIDER_MODE. Ключи хранятся в .env.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('ai_assistant')
                            ->label('AI-помощник на сайте')
                            ->helperText('Нужен настроенный провайдер и работающий обработчик очереди ai'),
                        TextInput::make('ai_cooldown_minutes')
                            ->label('Пауза модели после лимита, мин')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('0 — из .env (GEMINI_COOLDOWN_MINUTES, 60)'),
                        Textarea::make('ai_models')
                            ->label('Модели Gemini')
                            ->rows(4)
                            ->helperText('По одной на строку. Пусто — список из .env: '.implode(', ', config('ai.models')))
                            ->columnSpanFull(),
                    ]),

                Section::make('Уведомления')
                    ->schema([
                        Toggle::make('telegram_alerts')
                            ->label('Оповещения о заявках в Telegram')
                            ->helperText('Бот и chat_id задаются в .env. Выключатель — здесь.'),
                        Toggle::make('email_alerts')
                            ->label('Письмо о заявке на почту')
                            ->helperText('Уходит на почту компании ниже. Чтобы дошло в ящик, а не в лог, в .env нужен SMTP, не MAIL_MAILER=log.'),
                    ]),

                Section::make('Компания')
                    ->description('То, что печатается на сайте и в документах')
                    ->columns(2)
                    ->schema([
                        TextInput::make('brand_name')
                            ->label('Название на сайте')
                            ->helperText('Шапка, подвал, заголовок страниц'),
                        TextInput::make('warranty_months')
                            ->label('Гарантия по умолчанию, мес.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(60)
                            ->helperText('Если у предложения поставщика срок не указан'),
                        Textarea::make('phones')
                            ->label('Телефоны')
                            ->rows(3)
                            ->helperText('По одному номеру на строку — в таком виде их увидит покупатель')
                            ->columnSpanFull(),
                        TextInput::make('whatsapp_number')
                            ->label('WhatsApp')
                            ->helperText('Только цифры, как в wa.me: 77003375208'),
                        TextInput::make('instagram')
                            ->label('Instagram')
                            ->helperText('Имя профиля без @'),
                        TextInput::make('email')
                            ->label('Почта')
                            ->email()
                            ->helperText('Витрина и письма о заявках. Другой ящик смены — ORDER_ALERT_EMAIL в .env.'),
                        TextInput::make('hours')
                            ->label('Часы работы'),
                        TextInput::make('address')
                            ->label('Адрес')
                            ->columnSpanFull(),
                        Textarea::make('cities')
                            ->label('Города')
                            ->rows(3)
                            ->helperText('По одному на строку. Первый в списке — не обязан быть основным.')
                            ->columnSpanFull(),
                        TextInput::make('default_city')
                            ->label('Город по умолчанию')
                            ->helperText('Караганда, если покупатель ещё не выбрал'),
                    ]),

                Section::make('Реквизиты')
                    ->description('Для счетов и актов. Без БИН документ печатается с предупреждением.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_name')
                            ->label('Юридическое наименование')
                            ->columnSpanFull(),
                        TextInput::make('bin')
                            ->label('БИН')
                            ->maxLength(12),
                        TextInput::make('iik')
                            ->label('ИИК'),
                        TextInput::make('bank')
                            ->label('Банк'),
                        TextInput::make('bik')
                            ->label('БИК'),
                        TextInput::make('director')
                            ->label('Руководитель')
                            ->columnSpanFull(),
                        Toggle::make('vat_payer')
                            ->label('Плательщик НДС')
                            ->helperText('Влияет на формулировку в счетах и товарных чеках'),
                    ]),

                Section::make('Политика персональных данных')
                    ->description('Текст страницы /privacy. Ссылка на неё стоит у галочки согласия на формах.')
                    ->schema([
                        Textarea::make('privacy_policy')
                            ->label('Текст политики')
                            ->rows(16)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        app(AppSettings::class)->save($this->form->getState());

        Notification::make()
            ->title('Настройки сохранены')
            ->success()
            ->send();
    }
}
