<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\AcceptCashPayment;
use App\Enums\AssignmentType;
use App\Enums\DocumentType;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Logistics\Scheduler;
use App\Models\Order;
use App\Models\ServicePoint;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Список заявок — то, что менеджер держит открытым весь день.
 * Поэтому сортировка по новизне, а не по номеру, и телефон виден сразу:
 * первое действие по заявке — позвонить.
 */
class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'manager'])->withCount('items'))
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Order $r) => $r->source->label()),

                TextColumn::make('customer.name')
                    ->label('Клиент')
                    ->searchable()
                    ->description(fn (Order $r) => $r->customer->phone)
                    ->url(fn (Order $r) => 'tel:'.$r->customer->phone),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),

                TextColumn::make('items_count')
                    ->label('Позиций')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('total_amount')
                    ->label('Сумма')
                    ->money('KZT', locale: 'ru')
                    ->placeholder('не считана')
                    // Остаток важнее суммы: по нему решают, ехать ли за деньгами
                    ->description(fn (Order $r) => $r->outstanding() > 0.01
                        ? 'к оплате '.number_format($r->outstanding(), 0, ',', ' ').' ₸'
                        : 'оплачено')
                    ->color(fn (Order $r) => $r->outstanding() > 0.01 ? null : 'success'),

                TextColumn::make('manager.name')
                    ->label('Менеджер')
                    ->placeholder('ничья')
                    ->badge()
                    ->color(fn (?string $state) => $state ? 'primary' : 'warning'),

                TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->multiple()
                    ->options(OrderStatus::options()),

                SelectFilter::make('source')
                    ->label('Источник')
                    ->options(OrderSource::options()),

                SelectFilter::make('manager_id')
                    ->label('Менеджер')
                    ->relationship('manager', 'name')
                    ->searchable(),

                // Главный фильтр смены: то, за что никто не взялся
                Filter::make('unassigned')
                    ->label('Ничьи заявки')
                    ->query(fn (Builder $query) => $query->whereNull('manager_id')
                        ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])),
            ])
            ->defaultSort('created_at', 'desc')
            // Список менеджер держит открытым весь день — он должен показывать
            // новую заявку сам, без перезагрузки страницы.
            ->poll('30s')
            ->recordActions([
                Action::make('take')
                    ->label('Взять себе')
                    ->icon('heroicon-o-hand-raised')
                    ->color('primary')
                    ->visible(fn (Order $r) => $r->manager_id === null && ! $r->status->isFinal())
                    ->action(function (Order $record) {
                        $record->update(['manager_id' => Auth::id()]);

                        // Статус двигаем только через модель, чтобы взятие заявки
                        // попало в журнал: иначе в истории клиента будет разрыв —
                        // заявка сама собой оказалась «в работе», и непонятно, с кого спрос.
                        if ($record->status === OrderStatus::New) {
                            $record->transitionTo(OrderStatus::InProgress);
                        }

                        Notification::make()->title('Заявка ваша')->success()->send();
                    }),

                Action::make('status')
                    ->label('Сменить статус')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Order $r) => ! $r->status->isFinal())
                    ->schema(fn (Order $record) => [
                        Select::make('status')
                            ->label('Новый статус')
                            // Показываем только разрешённые переходы: список не даст
                            // вернуть выполненный заказ в начало воронки.
                            ->options(collect($record->status->allowedTransitions())
                                ->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()]))
                            ->required(),

                        Textarea::make('cancel_reason')
                            ->label('Причина отмены')
                            ->visible(fn (callable $get) => $get('status') === OrderStatus::Cancelled->value)
                            ->live(),
                    ])
                    ->action(function (Order $record, array $data) {
                        $target = OrderStatus::from($data['status']);

                        if (! $record->transitionTo($target)) {
                            Notification::make()->title('Такой переход запрещён')->danger()->send();

                            return;
                        }

                        if ($target === OrderStatus::Cancelled) {
                            $record->update(['cancel_reason' => $data['cancel_reason'] ?? null]);
                        }

                        Notification::make()->title('Статус обновлён')->success()->send();
                    }),

                Action::make('cash')
                    ->label('Принять оплату')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                                    // Кнопка есть только там, где есть что получать
                    ->visible(fn (Order $r) => ! $r->status->isFinal() && $r->outstanding() > 0.01)
                    ->schema(fn (Order $record) => [
                        TextInput::make('amount')
                            ->label('Сумма, ₸')
                            ->numeric()
                            ->required()
                            ->default((int) $record->outstanding())
                            ->helperText('Остаток по заявке: '
                                .number_format($record->outstanding(), 0, ',', ' ').' ₸'),
                    ])
                    ->action(function (Order $record, array $data) {
                        $payment = app(AcceptCashPayment::class)->handle(
                            order: $record,
                            amount: (float) $data['amount'],
                            receivedBy: Auth::user(),
                        );

                        Notification::make()
                            ->title($payment
                                ? 'Оплата принята'
                                : 'Сумма больше остатка по заявке')
                            ->status($payment ? 'success' : 'danger')
                            ->send();
                    }),

                Action::make('schedule')
                    ->label('Назначить работу')
                    ->icon('heroicon-o-calendar-days')
                    ->color('primary')
                    ->visible(fn (Order $r) => ! $r->status->isFinal())
                    ->schema([
                        Select::make('type')
                            ->label('Что делаем')
                            ->options(AssignmentType::options())
                            // подставляем то, о чём клиент просил в заявке
                            ->default(fn (Order $record) => match ($record->fulfillment) {
                                FulfillmentType::Delivery => AssignmentType::Delivery->value,
                                default => AssignmentType::Installation->value,
                            })
                            ->required()
                            ->live(),

                        DateTimePicker::make('scheduled_at')
                            ->label('Когда')
                            ->seconds(false)
                            ->required()
                            ->minDate(now()),

                        Select::make('service_point_id')
                            ->label('Сервис')
                            ->options(fn () => ServicePoint::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->visible(fn (callable $get) => $get('type') === AssignmentType::Installation->value),

                        Select::make('assignee_id')
                            ->label('Исполнитель')
                            ->options(fn (callable $get) => User::query()
                                ->where('is_active', true)
                                ->when($get('type'), fn ($query, $type) => $query->where(
                                    'role',
                                    AssignmentType::from($type)->performedBy()->value
                                ))
                                ->pluck('name', 'id'))
                            ->placeholder('назначим позже'),
                    ])
                    ->action(function (Order $record, array $data) {
                        $type = AssignmentType::from($data['type']);

                        $assignment = app(Scheduler::class)->schedule(
                            order: $record,
                            type: $type,
                            start: Carbon::parse($data['scheduled_at']),
                            point: isset($data['service_point_id'])
                ? ServicePoint::find($data['service_point_id'])
                : null,
                            assignee: isset($data['assignee_id']) ? User::find($data['assignee_id']) : null,
                        );

                        if ($assignment === null) {
                            // Планировщик отказал — показываем именно причину,
                            // иначе диспетчер будет тыкать время наугад.
                            $conflicts = app(Scheduler::class)->conflicts(
                                $type,
                                Carbon::parse($data['scheduled_at']),
                                $type->defaultDuration(),
                                isset($data['service_point_id'])
                                    ? ServicePoint::find($data['service_point_id'])
                                    : null,
                                isset($data['assignee_id']) ? User::find($data['assignee_id']) : null,
                            );

                            Notification::make()
                                ->title('Не удалось назначить')
                                ->body(implode('. ', array_map(fn ($c) => $c->message(), $conflicts)))
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['scheduled_at' => $assignment->scheduled_at]);
                        $record->transitionTo(OrderStatus::Scheduled);

                        Notification::make()->title('Работа назначена')->success()->send();
                    }),

                // Печать сгруппирована: документов пять, и россыпью
                // они вытеснили бы из строки всё остальное.
                ActionGroup::make([
                    Action::make('doc_receipt')
                        ->label(DocumentType::SalesReceipt->label())
                        ->icon('heroicon-o-document-text')
                        ->url(fn (Order $record) => route('document.order', [$record, DocumentType::SalesReceipt->value]))
                        ->openUrlInNewTab(),

                    Action::make('doc_warranty')
                        ->label(DocumentType::Warranty->label())
                        ->icon('heroicon-o-shield-check')
                        ->url(fn (Order $record) => route('document.order', [$record, DocumentType::Warranty->value]))
                        ->openUrlInNewTab(),

                    Action::make('doc_act')
                        ->label(DocumentType::HandoverAct->label())
                        ->icon('heroicon-o-clipboard-document-check')
                        ->url(fn (Order $record) => route('document.order', [$record, DocumentType::HandoverAct->value]))
                        ->openUrlInNewTab(),

                    Action::make('doc_invoice')
                        ->label(DocumentType::Invoice->label())
                        ->icon('heroicon-o-banknotes')
                        ->url(fn (Order $record) => route('document.order', [$record, DocumentType::Invoice->value]))
                        ->openUrlInNewTab(),
                ])
                    ->label('Документы')
                    ->icon('heroicon-o-printer')
                    ->button(),

                EditAction::make()->label('Открыть'),
            ])
            ->emptyStateHeading('Заявок пока нет')
            ->emptyStateDescription('Они появятся здесь, как только клиент отправит форму с сайта.');
    }
}
