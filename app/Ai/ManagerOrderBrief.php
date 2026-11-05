<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Providers\ChatProvider;
use App\Ai\Runtime\Conversation;
use App\Ai\Runtime\MessageText;
use App\Enums\PaymentStatus;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сводка заказа и черновик ответа клиенту — для менеджера в карточке заказа.
 *
 * ИИ предлагает, человек подтверждает: здесь ничего не пишется и никуда не
 * отправляется. Менеджер правит черновик и сам сохраняет его в историю
 * или отправляет в WhatsApp.
 *
 * Модели уходят только факты заказа, история общения и переписка с
 * помощником. Имя, телефон, адрес и e-mail вырезаются (PromptPrivacy):
 * для сводки они не нужны, а чужой сервис их хранить не должен.
 */
final class ManagerOrderBrief
{
    public const LOCALES = ['ru' => 'русском', 'kk' => 'казахском'];

    private const CHAT_MESSAGES = 30;

    private const INTERACTIONS = 15;

    public function __construct(private readonly ChatProvider $provider) {}

    /** @return array{summary:string,reply:string} */
    public function for(Order $order, string $locale = 'ru'): array
    {
        $locale = array_key_exists($locale, self::LOCALES) ? $locale : 'ru';
        $parts = $this->provider->generate(
            [['role' => 'user', 'parts' => [['text' => $this->context($order)]]]],
            $this->system($locale),
            [],
            new AiRequestBudget((float) config('ai.sync_budget_seconds', 20), (int) config('ai.max_attempts', 4)),
        );

        return self::parse(implode('', array_map(fn (array $part) => (string) ($part['text'] ?? ''), $parts)));
    }

    /** Язык чата покупателя — с него черновик и начинается. */
    public function preferredLocale(Order $order): string
    {
        return $this->conversation($order)?->locale === 'kk' ? 'kk' : 'ru';
    }

    /** @return array{summary:string,reply:string} */
    public static function parse(string $text): array
    {
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($text)) ?? $text);
        $data = json_decode($text, true);
        $summary = is_array($data) ? trim((string) ($data['summary'] ?? '')) : '';
        $reply = is_array($data) ? trim((string) ($data['reply'] ?? '')) : '';

        if ($summary === '' || $reply === '') {
            throw new AiProviderException('invalid_json', true);
        }

        return ['summary' => $summary, 'reply' => $reply];
    }

    public function context(Order $order): string
    {
        $order->loadMissing(['items', 'payments']);
        $paid = $order->payments->contains(fn ($payment) => $payment->status === PaymentStatus::Succeeded);

        $lines = [
            'ЗАКАЗ '.$order->number,
            'Статус: '.$order->status->label(),
            'Создан: '.$order->created_at?->format('d.m.Y H:i'),
            'Получение: '.($order->fulfillment?->label() ?? 'не выбрано'),
            'Оплата: '.($order->payment_method?->label() ?? 'не выбрана').($paid ? ', оплачено' : ', не оплачено'),
            'Сумма: '.($order->total_amount !== null ? number_format((float) $order->total_amount, 0, ',', ' ').' ₸' : 'не рассчитана'),
            'Время установки или доставки: '.($order->scheduled_at?->format('d.m.Y H:i') ?? 'не назначено'),
            'Позиции:',
            ...$order->items->map(fn ($item) => '- '.$item->name.($item->supplier_name ? ' ('.$item->supplier_name.')' : '')
                .' × '.$item->quantity.($item->price !== null ? ', '.number_format((float) $item->price, 0, ',', ' ').' ₸' : ''))->all(),
        ];
        if (filled($order->comment)) {
            $lines[] = 'Комментарий клиента: '.PromptPrivacy::text((string) $order->comment);
        }
        if (filled($order->manager_note)) {
            $lines[] = 'Заметка менеджера: '.PromptPrivacy::text((string) $order->manager_note);
        }

        $history = $order->interactions()->latest('occurred_at')->limit(self::INTERACTIONS)->get()->reverse();
        if ($history->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'ИСТОРИЯ ОБЩЕНИЯ (старые сверху):';
            foreach ($history as $entry) {
                /** @var Interaction $entry */
                $lines[] = '- '.$entry->occurred_at?->format('d.m H:i').' '.$entry->type->label().': '.PromptPrivacy::text((string) $entry->body);
            }
        }

        $chat = $this->conversation($order);
        if ($chat !== null) {
            $messages = DB::table('ai_messages')->where('conversation_id', $chat->id)
                ->latest('id')->limit(self::CHAT_MESSAGES)->get(['role', 'text'])->reverse();
            $lines[] = '';
            $lines[] = 'ПЕРЕПИСКА ПОКУПАТЕЛЯ С ПОМОЩНИКОМ НА САЙТЕ (последние сообщения):';
            foreach ($messages as $message) {
                $lines[] = ($message->role === 'user' ? 'Покупатель: ' : 'Помощник: ').PromptPrivacy::text(MessageText::decode($message->text));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Чат, из которого оформлен заказ, а если заказ пришёл не из чата —
     * последний чат того же покупателя в кабинете. Гостевые чаты без
     * заказа к клиенту не привязать — и не надо.
     */
    private function conversation(Order $order): ?Conversation
    {
        if ($order->purchase_draft_id !== null) {
            $id = DB::table('assistant_sessions')->where('draft_id', $order->purchase_draft_id)->value('conversation_id');
            if ($id !== null) {
                return Conversation::query()->find($id);
            }
        }

        $accounts = User::query()->where('customer_id', $order->customer_id)->pluck('id');

        return $accounts->isEmpty() ? null : Conversation::query()
            ->whereIn('actor_id', $accounts)->latest('updated_at')->first();
    }

    private function system(string $locale): string
    {
        return implode("\n", [
            'Ты помогаешь менеджеру магазина автостекла '.config('app.name').' в Караганде.',
            'По данным заказа, истории общения и переписке с помощником сделай две вещи:',
            '1. summary — сводка для менеджера на русском, не больше трёх коротких строк: что хочет клиент, что уже сделано, что осталось решить.',
            '2. reply — черновик сообщения клиенту в WhatsApp на '.self::LOCALES[$locale].' языке: вежливо, коротко, по делу, без канцелярита.',
            'Называй только цены, суммы, сроки и даты, которые есть в данных. Если срока или наличия в данных нет — напиши, что менеджер уточнит, и не обещай.',
            'Не придумывай скидки, акции и гарантии. Не пиши имя клиента — менеджер подставит сам.',
            'Ответь только JSON без пояснений: {"summary": "...", "reply": "..."}',
        ]);
    }
}
