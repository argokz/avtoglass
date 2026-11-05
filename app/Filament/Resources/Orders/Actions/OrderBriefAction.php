<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Ai\AiProviderException;
use App\Ai\ManagerOrderBrief;
use App\Enums\InteractionType;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * «ИИ: сводка и ответ» в карточке заказа.
 *
 * Модальное окно открывается уже со сводкой и черновиком. Менеджер правит
 * текст и сохраняет его в историю заказа; отправка в WhatsApp — ссылкой,
 * последнее нажатие всегда за человеком. В историю пишется, правил ли
 * менеджер черновик: по этой доле решаем, оставлять ли функцию (фаза D).
 */
final class OrderBriefAction
{
    public const NAME = 'aiBrief';

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label('ИИ: сводка и ответ')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->modalHeading(fn (Order $record) => 'Заказ '.$record->number.': сводка и черновик ответа')
            ->modalDescription('Черновик составлен по заказу, истории и переписке с помощником. Проверьте цены и сроки перед отправкой.')
            ->modalSubmitActionLabel('Сохранить в историю')
            ->fillForm(fn (Order $record) => self::draft($record, app(ManagerOrderBrief::class)->preferredLocale($record)))
            ->schema([
                Textarea::make('summary')->label('Сводка')->rows(3)->disabled()->dehydrated(false),
                Select::make('locale')->label('Язык ответа')->options(['ru' => 'Русский', 'kk' => 'Қазақша'])
                    ->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Order $record) {
                        $draft = self::draft($record, $state ?? 'ru');
                        $set('summary', $draft['summary']);
                        $set('reply', $draft['reply']);
                        $set('original', $draft['original']);
                    }),
                Textarea::make('reply')->label('Ответ клиенту')->rows(8)->required()->maxLength(4000),
                Hidden::make('original'),
            ])
            ->action(function (Order $record, array $data) {
                $reply = trim((string) $data['reply']);
                $edited = $reply !== trim((string) ($data['original'] ?? ''));
                Interaction::query()->create([
                    'customer_id' => $record->customer_id,
                    'order_id' => $record->id,
                    'user_id' => Auth::id(),
                    'type' => InteractionType::Note,
                    'body' => 'Ответ клиенту (черновик ИИ, '.($edited ? 'с правками' : 'без правок')."):\n".$reply,
                    'occurred_at' => now(),
                ]);

                $notification = Notification::make()->title('Ответ сохранён в историю заказа')->success();
                $phone = Customer::normalizePhone($record->customer?->phone);
                if ($phone !== null) {
                    $notification->body('Отправьте его клиенту в WhatsApp — текст уже подставлен.')
                        ->actions([Action::make('whatsapp')->label('Открыть WhatsApp')->button()
                            ->url('https://wa.me/'.$phone.'?text='.rawurlencode($reply), shouldOpenInNewTab: true)])
                        ->persistent();
                }
                $notification->send();
            });
    }

    /** @return array{summary:string,reply:string,original:string,locale:string} */
    private static function draft(Order $order, string $locale): array
    {
        try {
            $brief = app(ManagerOrderBrief::class)->for($order, $locale);
        } catch (AiProviderException $e) {
            Log::warning('ИИ-сводка заказа не получилась', ['order_id' => $order->id, 'reason' => $e->reason]);
            Notification::make()->title('ИИ сейчас не ответил')->body('Попробуйте через минуту или напишите ответ сами.')->warning()->send();
            $brief = ['summary' => 'Сводки нет: ИИ не ответил.', 'reply' => ''];
        }

        return [...$brief, 'original' => $brief['reply'], 'locale' => $locale];
    }
}
