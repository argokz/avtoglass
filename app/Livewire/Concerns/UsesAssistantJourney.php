<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\StartPayment;
use App\Ai\AssistantCheckout;
use App\Ai\AssistantOrderIntent;
use App\Ai\OrderReplies;
use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\ToolPayload;
use App\Domain\Assistant\ManagerRequests;
use App\Domain\Assistant\MemoryService;
use App\Domain\Assistant\ProcurementService;
use App\Domain\Assistant\ShoppingService;
use App\Domain\Ordering\CustomerPurchases;
use App\Domain\Ordering\PaymentOptions;
use App\Domain\Shared\PurchaseException;
use App\Wholesale\Import\PurchaseFileReader;
use App\Wholesale\Import\PurchaseImportException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

trait UsesAssistantJourney
{
    use WithFileUploads;

    #[Locked]
    public array $shopping = [];

    #[Locked]
    public array $rememberedVehicles = [];

    public array $contactDetails = ['name' => '', 'phone' => ''];

    public string $purchaseList = '';

    public $purchaseFile;

    public bool $showAllOffers = false;

    public string $slotDate = '';

    #[Locked]
    public array $checkoutSlots = [];

    /** Заказы, о которых спросили в последнем ответе: статус, оплата, отмена. */
    #[Locked]
    public array $orderCards = [];

    /** Номер заказа, отмену которого покупатель начал, но ещё не подтвердил. */
    public string $cancelling = '';

    public string $cancelReason = '';

    public function loadCheckoutSlots(string $checkoutId): void
    {
        $this->refreshChat();
        $card = collect($this->checkouts)->firstWhere('id', $checkoutId);
        abort_unless($card && $card['status'] !== 'submitted', 404);
        $pointId = (int) ($this->checkoutEdits[$checkoutId]['service_point_id'] ?? $card['fulfillment']['service_point_id'] ?? 0);
        $this->checkoutSlots[$checkoutId] = app(AssistantCheckout::class)->slots(ChatIdentity::buyer(), $checkoutId, $pointId, $this->slotDate);
        if ($this->checkoutSlots[$checkoutId] === []) {
            $this->notice = $this->say('На эту дату свободных интервалов нет. Выберите другой день или пункт.', 'Бұл күнге бос аралық жоқ. Басқа күнді немесе орынды таңдаңыз.');
        }
    }

    public function chooseCheckoutSlot(string $checkoutId, int $index): void
    {
        $slot = $this->checkoutSlots[$checkoutId][$index] ?? null;
        abort_unless($slot, 404);
        $this->checkoutEdits[$checkoutId] = array_replace($this->checkoutEdits[$checkoutId] ?? [], array_intersect_key($slot, array_flip(['service_point_id', 'slot_start', 'slot_end'])));
        $this->updateCheckoutDetails($checkoutId);
        unset($this->checkoutSlots[$checkoutId]);
    }

    public function requestManager(): void
    {
        $chat = $this->conversation();
        if (! $chat || $chat->active_turn_id || ! $this->allowAttempt($chat)) {
            return;
        }
        try {
            $task = app(ManagerRequests::class)->create(ChatIdentity::buyer(), $chat->id, $this->contactDetails);
            $this->notice = $this->say('Обращение передано менеджеру. Номер: ', 'Сұрау менеджерге жіберілді. Нөмірі: ').$task->id;
        } catch (ValidationException|PurchaseException) {
            $this->notice = $this->say('Для обращения укажите имя и телефон в форме.', 'Сұрау үшін формада атыңыз бен телефоныңызды көрсетіңіз.');
        }
    }

    private function refreshJourney($chat): void
    {
        if (! Schema::hasTable('assistant_sessions')) {
            return;
        }
        $buyer = ChatIdentity::buyer();
        $this->shopping = app(ShoppingService::class)->context($chat, $buyer);
        $this->rememberedVehicles = app(MemoryService::class)->values($buyer)['vehicles'] ?? [];
    }

    public function useRememberedVehicle(string $id): void
    {
        $chat = $this->conversation();
        if (! $chat || $chat->active_turn_id) {
            return;
        }
        $vehicle = app(ShoppingService::class)->useVehicle($chat, ChatIdentity::buyer(), $id);
        $this->draft = $vehicle['label'].' '.($vehicle['year'] ?? '');
        $this->refreshChat();
    }

    public function answerOption(string $flag, string $answer): void
    {
        abort_unless(in_array($answer, ['yes', 'no', 'unknown'], true), 422);
        $chat = $this->conversation();
        if (! $chat) {
            return;
        }
        app(ShoppingService::class)->answerOption($chat, ChatIdentity::buyer(), $flag, match ($answer) {
            'yes' => true, 'no' => false, default => null
        });
        if ($answer === 'unknown') {
            $this->notice = $this->say('Комплектацию проверит менеджер. Выберите вариант для заявки или обратитесь к менеджеру.', 'Жабдықталуды менеджер тексереді. Өтінім үшін нұсқаны таңдаңыз немесе менеджерге хабарласыңыз.');
            $this->refreshChat();

            return;
        }
        $this->draft = $answer === 'yes' ? $this->say('Да', 'Иә') : $this->say('Нет', 'Жоқ');
        $this->send();
    }

    public function reviewPurchaseList(): void
    {
        $chat = $this->conversation();
        if (! $chat || $chat->active_turn_id) {
            return;
        }
        $this->validate(['purchaseList' => ['required', 'string', 'max:64000']]);
        $rows = [];
        foreach (preg_split('/\R/u', $this->purchaseList) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = preg_split('/\s*[;\t]\s*|\s+[xх×]\s*|\s+(?=\d+\s*$)/u', trim($line));
            $rows[] = ['article' => $parts[0] ?? '', 'quantity' => $parts[1] ?? 1];
        }
        app(ProcurementService::class)->replace(ChatIdentity::buyer(), $chat->id, $rows);
        $this->refreshChat();
    }

    public function reviewPurchaseFile(): void
    {
        $chat = $this->conversation();
        if (! $chat || $chat->active_turn_id) {
            return;
        }
        $this->validate(['purchaseFile' => ['required', 'file', 'max:20480']]);
        try {
            $data = app(PurchaseFileReader::class)->read($this->purchaseFile->getRealPath(), $this->purchaseFile->getClientOriginalExtension());
            app(ProcurementService::class)->replace(ChatIdentity::buyer(), $chat->id, array_values($data['rows']));
        } catch (PurchaseImportException $exception) {
            $this->addError('purchaseFile', __('shop.'.$exception->reason));
        } finally {
            $this->purchaseFile->delete();
            $this->purchaseFile = null;
        }
        $this->refreshChat();
    }

    public function choosePurchaseLine(string $lineId, int $offerId): void
    {
        if ($chat = $this->conversation()) {
            try {
                app(ProcurementService::class)->choose(ChatIdentity::buyer(), $chat->id, $lineId, $offerId);
            } catch (PurchaseException $exception) {
                if (! in_array($exception->errorCode, ['OFFER_SELECTION_REQUIRED', 'CONVERSATION_BUSY'], true)) {
                    throw $exception;
                }
                $this->notice = $this->say('Список изменился. Проверьте текущие строки и повторите выбор.', 'Тізім өзгерді. Ағымдағы жолдарды тексеріп, таңдауды қайталаңыз.');
            }
            $this->refreshChat();
        }
    }

    public function prepareSelected(): void
    {
        $chat = $this->conversation();
        if (! $chat || $chat->active_turn_id) {
            return;
        }
        $context = app(ShoppingService::class)->context($chat, ChatIdentity::buyer());
        if (($context['lines'] ?? []) !== []) {
            $this->prepareAllOffers();
        } elseif (($context['selected'][0] ?? null) !== null) {
            $this->prepareOffer($context['selected'][0]);
        }
    }

    private function shoppingIntent($chat): array
    {
        $intent = Schema::hasTable('assistant_sessions') ? app(ShoppingService::class)->context($chat, ChatIdentity::buyer())['intent'] : AssistantOrderIntent::fromMessages($this->messages, settings()->cities());
        foreach (['name', 'phone'] as $field) {
            if (trim((string) ($this->contactDetails[$field] ?? '')) !== '') {
                $intent[$field] = trim($this->contactDetails[$field]);
            }
        }

        return $intent;
    }

    /**
     * Карточки берём из последнего ответа помощника, а статус — живой:
     * за минуту, пока человек читает, менеджер мог заказ подтвердить.
     * Владение проверяется заново — токен в снимке ещё не право доступа.
     */
    private function latestOrderCards($chat): array
    {
        $lastTurn = DB::table('ai_turns')->where('conversation_id', $chat->id)->where('status', 'completed')
            ->orderByDesc('model_message_id')->orderByDesc('attempt')->value('id');
        if ($lastTurn === null) {
            return [];
        }
        $rows = DB::table('ai_tool_results')->where('turn_id', $lastTurn)
            ->whereIn('name', ['list_orders', 'get_order', 'cancel_order', 'pay_order'])->orderBy('id')->get(['name', 'result']);
        $cards = [];
        $purchases = app(CustomerPurchases::class);
        foreach ($rows as $row) {
            try {
                $result = ToolPayload::decode($row->result);
            } catch (\JsonException) {
                continue;
            }
            $tokens = array_column(is_array($result['orders'] ?? null) ? array_slice($result['orders'], 0, 5) : [$result['order'] ?? []], 'id');
            foreach ($tokens as $token) {
                try {
                    $order = $purchases->owned(ChatIdentity::buyer(), (string) $token);
                } catch (PurchaseException) {
                    continue;
                }
                $cards[$order->public_token] = [...$purchases->view($order), 'focus' => $row->name,
                    'first_item' => $order->items()->value('name'),
                    'url' => $order->purchase_draft_id ? route('purchase.show', $order->public_token) : route('order.track', $order)];
            }
        }

        return array_values($cards);
    }

    public function payOrder(string $token): void
    {
        $buyer = ChatIdentity::buyer();
        $order = app(CustomerPurchases::class)->owned($buyer, $token);
        $driver = PaymentOptions::driver($order->payment_method?->value ?? '');
        if (! $driver || ! array_key_exists($order->payment_method->value, PaymentOptions::available($buyer))) {
            $this->notice = $this->say('Этот заказ оплачивается при получении или по счёту.', 'Бұл тапсырыс алған кезде немесе шот бойынша төленеді.');

            return;
        }
        session(['last_order_number' => $order->number]);
        if ($url = app(StartPayment::class)->begin($order, $driver)) {
            $this->redirect($url, navigate: false);

            return;
        }
        $this->notice = $this->say('Оплата сейчас недоступна. Проверьте статус заказа.', 'Төлем қазір қолжетімсіз. Тапсырыс мәртебесін тексеріңіз.');
    }

    public function repeatOrder(string $token): void
    {
        $draft = app(CustomerPurchases::class)->repeat(ChatIdentity::buyer(), $token, 'chat-repeat:'.Str::uuid());
        $this->redirectRoute('assistant', ['resume_draft' => $draft['id']], navigate: false);
    }

    public function startCancel(string $token): void
    {
        $this->cancelling = $token;
        $this->cancelReason = '';
    }

    public function confirmCancel(): void
    {
        $this->validate(['cancelReason' => ['nullable', 'string', 'max:500']]);
        try {
            $order = app(CustomerPurchases::class)->cancel(ChatIdentity::buyer(), $this->cancelling, $this->cancelReason);
            $this->notice = $this->say('Заказ '.$order->number.' отменён. Менеджер получил уведомление.', $order->number.' тапсырысынан бас тартылды. Менеджерге хабарланды.');
        } catch (PurchaseException $exception) {
            $this->notice = OrderReplies::cancel(['order' => ['number' => ''], 'cancel' => ['code' => $exception->errorCode === 'RESOURCE_NOT_FOUND' ? 'MANAGER_REQUIRED' : $exception->errorCode]], app()->getLocale() === 'kk');
        }
        $this->cancelling = '';
        $this->refreshChat();
    }

    public function payCheckout(string $checkoutId): void
    {
        $this->refreshChat();
        $card = collect($this->checkouts)->firstWhere('id', $checkoutId);
        abort_unless($card && ($card['status'] ?? '') === 'submitted', 404);
        $order = app(CustomerPurchases::class)->owned(ChatIdentity::buyer(), (string) ($card['result']['id'] ?? ''));
        session(['last_order_number' => $order->number]);
        $driver = PaymentOptions::driver($order->payment_method?->value ?? '');
        abort_unless($driver && array_key_exists($order->payment_method->value, PaymentOptions::available(ChatIdentity::buyer())), 409);
        if ($url = app(StartPayment::class)->begin($order, $driver)) {
            $this->redirect($url, navigate: false);
        } else {
            $this->notice = $this->say('Оплата сейчас недоступна. Проверьте статус и резерв заказа.', 'Төлем қазір қолжетімсіз. Тапсырыс мәртебесі мен резервін тексеріңіз.');
        }
    }
}
