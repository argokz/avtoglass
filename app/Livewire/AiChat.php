<?php

namespace App\Livewire;

use App\Ai\AssistantCheckout;
use App\Ai\AssistantOrderIntent;
use App\Ai\AssistantSearch;
use App\Ai\AssistantTools;
use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\Conversation;
use App\Ai\Runtime\GenerateReply;
use App\Ai\Runtime\MessageText;
use App\Ai\Runtime\ToolPayload;
use App\Domain\Assistant\ProcurementService;
use App\Domain\Assistant\ShoppingService;
use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\ActiveDraft;
use App\Domain\Ordering\CustomerPurchases;
use App\Domain\Ordering\DraftService;
use App\Domain\Shared\PurchaseException;
use App\Models\CarModel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AiChat extends Component
{
    use Concerns\UsesAssistantJourney;

    public bool $open = false;

    public bool $embedded = false;

    public string $draft = '';

    public array $privacyConsent = [];

    public array $checkoutEdits = [];

    #[Locked]
    public bool $identityUnavailable = false;

    #[Locked]
    public array $messages = [];

    #[Locked]
    public array $checkouts = [];

    #[Locked]
    public array $offers = [];

    #[Locked]
    public bool $canRequestManagerSelection = false;

    #[Locked]
    public string $lastSearchQuery = '';

    #[Locked]
    public ?string $activeStatus = null;

    #[Locked]
    public bool $canRetry = false;

    #[Locked]
    public string $notice = '';

    #[Locked]
    public ?string $conversationId = null;

    #[Locked]
    public array $conversations = [];

    public function mount(bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $this->open = $embedded;
        if ($embedded) {
            $this->refreshChat();
            if ($draftId = request()->query('resume_draft')) {
                $buyer = ChatIdentity::buyer();
                $draft = app(DraftService::class)->get($buyer, (string) $draftId)->jsonSerialize();
                abort_unless($draft['status'] === 'active', 409);
                $chat = $this->conversation();
                app(ProcurementService::class)->replace($buyer, $chat->id, array_map(fn ($line) => ['article' => $line['reference'], 'quantity' => $line['quantity'], 'manufacturer' => $line['manufacturer'] ?? ''], $draft['items']));
                app(ShoppingService::class)->get($chat)->forceFill(['draft_id' => $draft['id']])->save();
                app(ActiveDraft::class)->bind($buyer, $draft['id']);
                $this->refreshChat();
            }
        }
    }

    public function openChat(): void
    {
        $this->open = true;
        $this->refreshChat();
    }

    public function newConversation(): void
    {
        $current = $this->conversation();
        if (! $current || ! $this->allowAttempt($current)) {
            return;
        }
        $buyer = ChatIdentity::buyer();
        if (Conversation::query()->where('owner_key', ChatIdentity::ownerKey($buyer))->count() >= 50) {
            $this->notice = $this->say('Удалите старый чат, чтобы создать новый (лимит 50).', 'Жаңа чат ашу үшін ескі чатты жойыңыз (шегі 50).');

            return;
        }
        $chat = app(ChatStore::class)->create($buyer);
        session([$this->selectionKey($buyer) => $chat->id]);
        $this->conversationId = null;
        $this->refreshChat();
    }

    public function selectConversation(string $id): void
    {
        $buyer = ChatIdentity::buyer();
        abort_unless(Conversation::query()->where('owner_key', ChatIdentity::ownerKey($buyer))->whereKey($id)->exists(), 404);
        session([$this->selectionKey($buyer) => $id]);
        $this->refreshChat();
    }

    public function deleteConversation(string $id): void
    {
        $buyer = ChatIdentity::buyer();
        abort_unless(Conversation::query()->where('owner_key', ChatIdentity::ownerKey($buyer))->whereKey($id)->exists(), 404);
        app(ChatStore::class)->delete($buyer, $id);
        if (session($this->selectionKey($buyer)) === $id) {
            session()->forget($this->selectionKey($buyer));
        }
        $this->refreshChat();
    }

    private function selectionKey(BuyerContext $buyer): string
    {
        return 'ai_chat.selected.'.hash('sha256', ChatIdentity::ownerKey($buyer));
    }

    public function hydrate(): void
    {
        // Also protects requests such as opening the floating widget after an account switch.
        $this->conversation();
    }

    /** Current authenticated owner is authoritative, never the component's public state. */
    private function conversation(): ?Conversation
    {
        abort_unless(settings()->aiChatEnabled(), 404);
        try {
            $buyer = ChatIdentity::buyer();
        } catch (PurchaseException) {
            $this->shopping = $this->rememberedVehicles = [];
            $this->contactDetails = ['name' => '', 'phone' => ''];
            $this->purchaseList = '';
            $this->purchaseFile = null;
            $this->checkoutSlots = [];
            $this->slotDate = '';
            $this->messages = $this->checkouts = $this->offers = $this->orderCards = $this->privacyConsent = $this->checkoutEdits = $this->conversations = [];
            $this->draft = $this->lastSearchQuery = '';
            $this->conversationId = $this->activeStatus = null;
            $this->canRetry = $this->canRequestManagerSelection = false;
            $this->identityUnavailable = true;
            $this->notice = $this->say('Чат доступен покупателям и гостям. Для подбора обратитесь к менеджеру.', 'Чат сатып алушылар мен қонақтарға қолжетімді. Таңдау үшін менеджерге хабарласыңыз.');

            return null;
        }
        $this->identityUnavailable = false;
        $chat = app(ChatStore::class)->conversation($buyer, session($this->selectionKey($buyer)));
        session([$this->selectionKey($buyer) => $chat->id]);
        $chat->locale = $buyer->locale;
        if ($this->conversationId !== $chat->id) {
            $this->shopping = $this->rememberedVehicles = [];
            $this->contactDetails = ['name' => '', 'phone' => ''];
            $this->purchaseList = '';
            $this->purchaseFile = null;
            $this->checkoutSlots = [];
            $this->slotDate = '';
            $this->messages = [];
            $this->checkouts = [];
            $this->offers = [];
            $this->draft = '';
            $this->privacyConsent = [];
            $this->checkoutEdits = [];
            $this->notice = '';
            $this->activeStatus = null;
            $this->canRetry = false;
            $this->canRequestManagerSelection = false;
            $this->lastSearchQuery = '';
        }
        $this->conversationId = $chat->id;

        return $chat;
    }

    public function send(): void
    {
        $chat = $this->conversation();
        if (! $chat) {
            return;
        }
        $text = trim($this->draft);
        if ($text === '' || mb_strlen($text) > 1000 || ! $this->allowAttempt($chat)) {
            return;
        }
        $id = app(ChatStore::class)->enqueue($chat, $text);
        if ($id !== null) {
            $this->draft = '';
            $this->dispatchTurn($id);
        }
        $this->refreshChat();
    }

    public function retry(): void
    {
        $chat = $this->conversation();
        if (! $chat) {
            return;
        }
        if (! $this->allowAttempt($chat)) {
            return;
        }
        $id = app(ChatStore::class)->enqueue($chat, '', true);
        if ($id !== null) {
            $this->dispatchTurn($id);
        }
        $this->refreshChat();
    }

    public function cancel(): void
    {
        $chat = $this->conversation()?->fresh();
        if (! $chat) {
            return;
        }
        if ($chat->active_turn_id) {
            app(ChatStore::class)->write($chat->active_turn_id, null, 'cancelled');
        }
        $this->refreshChat();
    }

    private function dispatchTurn(string $id): void
    {
        $this->notice = '';
        $this->privacyConsent = [];
        try {
            GenerateReply::dispatch($id)->afterCommit();
        } catch (\Throwable) {
            app(ChatStore::class)->write($id, null, 'failed', 'queue_unavailable');
        }
    }

    private function allowAttempt(Conversation $chat): bool
    {
        $key = 'ai-chat:'.$chat->owner_key;
        if (RateLimiter::tooManyAttempts($key, (int) config('ai.rate_limit_per_minute', 20))) {
            $this->notice = $this->say('Слишком много сообщений подряд — подождите минуту.', 'Хабарламалар тым көп — бір минут күтіңіз.');

            return false;
        }
        RateLimiter::hit($key, 60);

        return true;
    }

    public function refreshChat(): void
    {
        $chat = $this->conversation();
        if (! $chat) {
            return;
        }
        app(ChatStore::class)->recover($chat);
        $chat->refresh();
        $this->conversations = Conversation::query()->where('owner_key', $chat->owner_key)
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(50)->get(['id', 'title', 'updated_at'])
            ->map(fn ($row) => ['id' => $row->id, 'title' => $row->title ?: $this->say('Новый чат', 'Жаңа чат'), 'date' => $row->updated_at->format('d.m.Y')])->all();
        $this->messages = DB::table('ai_messages')->where('conversation_id', $chat->id)->orderByDesc('id')->limit(100)->get(['id', 'role', 'text'])
            ->reverse()->map(fn ($row) => [...(array) $row, 'text' => MessageText::decode($row->text)])->values()->all();
        if ($this->messages === []) {
            $this->messages = [['id' => 'greeting', 'role' => 'model', 'text' => $this->say(
                'Здравствуйте! Помогу подобрать стекло и оформить заявку. Напишите марку и модель авто или еврокод. По готовому заказу можно написать «статус заказа 78», «оплатить заказ 78» или «отменить заказ 78».',
                'Сәлеметсіз бе! Автоәйнек таңдауға және өтінім беруге көмектесемін. Көліктің маркасы мен моделін немесе еурокодын жазыңыз. Дайын тапсырыс бойынша «78 тапсырыс мәртебесі», «78 тапсырысты төлеу» деп жазуға болады.',
            )]];
        }
        $last = DB::table('ai_turns')->where('conversation_id', $chat->id)->orderByDesc('model_message_id')->orderByDesc('attempt')->first();
        $this->activeStatus = $chat->active_turn_id ? DB::table('ai_turns')->where('id', $chat->active_turn_id)->value('status') : null;
        $this->canRetry = $last && in_array($last->status, ['failed', 'cancelled'], true);
        if ($this->canRetry) {
            $this->notice = $last->status === 'cancelled'
                ? $this->say('Ответ остановлен. Можно повторить запрос.', 'Жауап тоқтатылды. Сұрауды қайталауға болады.')
                : $this->say('Не получилось ответить. Помощник сейчас на паузе — повторите запрос или подберите стекло в каталоге.', 'Жауап алу мүмкін болмады. Сұрауды қайталаңыз немесе каталогтан әйнек таңдаңыз.');
        }
        $turnIds = DB::table('ai_turns')->where('conversation_id', $chat->id)->where('status', 'completed')->orderByDesc('model_message_id')->limit(100)->pluck('operation_id')->reverse()->all();
        $cards = app(AssistantCheckout::class)->pendingForTurns(ChatIdentity::buyer(), $turnIds);
        foreach ($cards as $card) {
            $previous = collect($this->checkouts)->firstWhere('id', $card['id']);
            if (($previous['quote_id'] ?? null) !== $card['quote_id']) {
                unset($this->privacyConsent[$card['id']]);
                $this->checkoutEdits[$card['id']] = [
                    'name' => (string) ($card['contacts']['name'] ?? ''),
                    'phone' => (string) ($card['contacts']['phone'] ?? ''),
                    'fulfillment' => (string) ($card['fulfillment']['type'] ?? 'pickup'),
                    'city' => (string) ($card['fulfillment']['city'] ?? settings()->defaultCity()),
                    'address' => (string) ($card['fulfillment']['address'] ?? ''),
                    'payment_method' => (string) ($card['payment_method'] ?? 'cash'),
                    'service_point_id' => $card['fulfillment']['service_point_id'] ?? '',
                ];
            } elseif (! isset($this->checkoutEdits[$card['id']])) {
                $this->checkoutEdits[$card['id']] = [
                    'name' => (string) ($card['contacts']['name'] ?? ''),
                    'phone' => (string) ($card['contacts']['phone'] ?? ''),
                    'fulfillment' => (string) ($card['fulfillment']['type'] ?? 'pickup'),
                    'city' => (string) ($card['fulfillment']['city'] ?? settings()->defaultCity()),
                    'address' => (string) ($card['fulfillment']['address'] ?? ''),
                    'payment_method' => (string) ($card['payment_method'] ?? 'cash'),
                    'service_point_id' => $card['fulfillment']['service_point_id'] ?? '',
                ];
            }
        }
        $this->checkouts = $this->decorateCheckouts($cards);
        $this->offers = $this->latestSearchOffers($chat);
        $this->refreshJourney($chat);
        $this->orderCards = $this->latestOrderCards($chat);
    }

    public function chooseOffer(int $offerId): void
    {
        $offer = collect($this->offers)->firstWhere('offer_id', $offerId);
        if (! is_array($offer)) {
            return;
        }
        $name = trim((string) ($offer['name'] ?? ''));
        $this->draft = $name === ''
            ? $this->say('Беру offer_id '.$offerId, 'offer_id '.$offerId.' аламын')
            : $this->say('Беру offer_id '.$offerId.': '.$name, 'offer_id '.$offerId.' аламын: '.$name);
    }

    /** Fast-path checkout preparation for a chosen offer without waiting for an LLM round-trip when contacts are known. */
    public function prepareOffer(int $offerId): void
    {
        $chat = $this->conversation();
        if (! $chat || $this->activeStatus !== null) {
            return;
        }
        $offer = collect($this->offers)->firstWhere('offer_id', $offerId);
        if (! is_array($offer)) {
            return;
        }
        $buyer = ChatIdentity::buyer();
        $intent = $this->shoppingIntent($chat);
        $intent['offer_id'] = $offerId;
        if (Schema::hasTable('assistant_sessions')) {
            app(ShoppingService::class)->selectOffer($chat, $buyer, $offerId, $offer);
        }
        $args = AssistantOrderIntent::checkoutArguments([
            'offer_id' => $offerId,
            'quantity' => max(1, (int) ($offer['requested_qty'] ?? $intent['quantity'] ?? 1)),
        ], $intent, $buyer);
        if (empty($args['name']) || empty($args['phone'])) {
            $this->draft = '';
            $this->notice = $this->say('Укажите имя и телефон в форме для расчёта.', 'Есеп үшін формада атыңыз бен телефоныңызды көрсетіңіз.');
            $this->refreshChat();

            return;
        }
        $title = trim((string) ($offer['name'] ?? ''));
        $userText = $title === ''
            ? $this->say('Беру offer_id '.$offerId, 'offer_id '.$offerId.' аламын')
            : $this->say('Беру offer_id '.$offerId.': '.$title, 'offer_id '.$offerId.' аламын: '.$title);
        $this->executeFastPrepare($chat, $buyer, $userText, $args);
    }

    /** Fast-path multi-item checkout preparation for wholesale or multi-article lists. */
    public function prepareAllOffers(): void
    {
        $chat = $this->conversation();
        if (! $chat || $this->activeStatus !== null) {
            return;
        }
        $buyer = ChatIdentity::buyer();
        $intent = $this->shoppingIntent($chat);
        $items = app(ProcurementService::class)->items(app(ShoppingService::class)->get($chat));
        if ($items === []) {
            $this->notice = $this->say('Сначала проверьте строки списка и выберите предложения.', 'Алдымен тізім жолдарын тексеріп, ұсыныстарды таңдаңыз.');

            return;
        }
        $args = AssistantOrderIntent::checkoutArguments(['items' => $items], $intent, $buyer);
        unset($args['offer_id'], $args['quantity']);
        if (empty($args['name']) || empty($args['phone'])) {
            $this->notice = $this->say('Укажите имя и телефон в форме для подготовки расчёта.', 'Есеп дайындау үшін формада атыңыз бен телефоныңызды көрсетіңіз.');
            $this->refreshChat();

            return;
        }
        $userText = $this->say('Подготовить расчёт по выбранным строкам закупки', 'Таңдалған сатып алу жолдары бойынша есеп дайындау');
        $this->executeFastPrepare($chat, $buyer, $userText, $args, $items);
    }

    /** Fast-path manager selection request when catalog lookup returned no items. */
    public function prepareUnresolvedRequest(): void
    {
        $chat = $this->conversation();
        if (! $chat || $this->activeStatus !== null) {
            return;
        }
        $buyer = ChatIdentity::buyer();
        $intent = $this->shoppingIntent($chat);
        $ref = trim($this->lastSearchQuery) !== '' ? trim($this->lastSearchQuery) : $this->say('Подбор автостекла', 'Автоәйнек таңдау');
        $args = AssistantOrderIntent::checkoutArguments(['reference' => $ref], $intent, $buyer);
        unset($args['offer_id']);
        if (empty($args['name']) || empty($args['phone'])) {
            $this->draft = $this->say(
                'Подготовь заявку менеджеру на подбор ('.$ref.'). Имя: , телефон: +7',
                'Менеджерге таңдауға өтінім дайында ('.$ref.'). Аты: , телефон: +7',
            );
            $this->notice = $this->say('Укажите имя и телефон в поле ввода и нажмите Отправить.', 'Енгізу өрісіне атыңыз бен телефоныңызды жазып, Жіберу түймесін басыңыз.');

            return;
        }
        $userText = $this->say('Заявка менеджеру на подбор: '.$ref, 'Менеджерге таңдауға өтінім: '.$ref);
        $this->executeFastPrepare($chat, $buyer, $userText, $args);
    }

    private function executeFastPrepare(Conversation $chat, BuyerContext $buyer, string $userText, array $args, ?array $trustedItems = null): void
    {
        if (! $this->allowAttempt($chat)) {
            return;
        }
        $store = app(ChatStore::class);
        $turnId = $store->enqueue($chat, $userText);
        if ($turnId === null) {
            return;
        }
        $claimed = $store->claim($turnId);
        if (! $claimed) {
            return;
        }
        try {
            $result = $trustedItems === null ? app(AssistantTools::class)->call('prepare_order', $args, $buyer, $turnId)
                : app(AssistantCheckout::class)->prepare($buyer, $args, $turnId, $trustedItems);
        } catch (PurchaseException|ValidationException $e) {
            $result = ['code' => $e instanceof PurchaseException ? $e->errorCode : 'VALIDATION_FAILED'];
        }
        $store->recordToolResult($turnId, 'prepare_order', $args, $result);
        if (($result['ok'] ?? false) && isset($result['checkout'])) {
            $reply = $this->say(
                'Заявка подготовлена. Проверьте карточку ниже и подтвердите отправку кнопкой.',
                'Өтінім дайындалды. Төмендегі карточканы тексеріп, жіберуді батырмамен растаңыз.',
            );
            $store->write($turnId, $reply, 'completed');
            $this->notice = '';
        } else {
            $reply = $this->say(
                'Для подготовки заявки уточните контактное имя, телефон и способ получения.',
                'Өтінімді дайындау үшін атыңызды, телефоныңызды және алу тәсілін нақтылаңыз.',
            );
            $store->write($turnId, $reply, 'completed');
        }
        $this->refreshChat();
    }

    public function updateCheckoutDetails(string $checkoutId): void
    {
        $this->refreshChat();
        if (! collect($this->checkouts)->contains(fn ($card) => $card['id'] === $checkoutId && ($card['status'] ?? '') !== 'submitted')) {
            return;
        }
        $edits = $this->checkoutEdits[$checkoutId] ?? [];
        if (! is_array($edits)) {
            return;
        }
        unset($this->privacyConsent[$checkoutId]);
        try {
            app(AssistantCheckout::class)->updateDetails(ChatIdentity::buyer(), $checkoutId, $edits);
            $this->notice = $this->say('Данные заявки обновлены. Проверьте расчёт и подтвердите отправку.', 'Өтінім деректері жаңартылды. Есепті тексеріп, жіберуді растаңыз.');
        } catch (\Throwable) {
            $this->notice = $this->say('Не удалось обновить данные заявки. Проверьте имя, телефон и адрес.', 'Өтінім деректерін жаңарту мүмкін болмады. Атыңызды, телефонды және мекенжайды тексеріңіз.');
        }
        $this->refreshChat();
    }

    public function confirmCheckout(string $checkoutId): void
    {
        $shown = collect($this->checkouts)->firstWhere('id', $checkoutId);
        $this->refreshChat();
        $allowed = collect($this->checkouts)->contains(fn ($card) => $card['id'] === $checkoutId && ($card['quote_id'] ?? null) === ($shown['quote_id'] ?? null));
        if (! $allowed || ! ($this->privacyConsent[$checkoutId] ?? false)) {
            $this->notice = $this->say('Проверьте заявку и подтвердите согласие на обработку персональных данных.', 'Өтінімді тексеріп, жеке деректерді өңдеуге келісім беріңіз.');

            return;
        }
        try {
            $result = app(AssistantCheckout::class)->confirm(ChatIdentity::buyer(), $checkoutId, (bool) $this->privacyConsent[$checkoutId], $shown['quote_id']);
            $this->notice = $this->say('Заявка принята. Номер: ', 'Өтінім қабылданды. Нөмірі: ').($result['order_number'] ?? '');
            $this->privacyConsent = [];
        } catch (\Throwable) {
            $this->notice = $this->say('Не удалось подтвердить заявку. Обновите подбор: цена или наличие могли измениться.', 'Өтінімді растау мүмкін болмады. Баға немесе қор өзгерген болуы мүмкін — таңдауды жаңартыңыз.');
        }
        $this->refreshChat();
    }

    public function refreshCheckout(string $checkoutId): void
    {
        $this->refreshChat();
        if (! collect($this->checkouts)->contains(fn ($card) => $card['id'] === $checkoutId)) {
            return;
        }
        $this->privacyConsent = [];
        try {
            app(AssistantCheckout::class)->refresh(ChatIdentity::buyer(), $checkoutId);
            $this->notice = $this->say('Расчёт обновлён. Проверьте условия перед подтверждением.', 'Есеп жаңартылды. Растамас бұрын шарттарды тексеріңіз.');
        } catch (\Throwable) {
            $this->notice = $this->say('Не удалось обновить расчёт. Уточните подбор у помощника.', 'Есепті жаңарту мүмкін болмады. Таңдауды көмекшімен нақтылаңыз.');
        }
        $this->refreshChat();
    }

    public function money(?array $money): string
    {
        if ($money === null) {
            return $this->say('Уточнит менеджер', 'Менеджер нақтылайды');
        }

        $minor = (int) $money['amount_minor'];
        $absolute = abs($minor);
        $amount = ($minor < 0 ? '-' : '').number_format(intdiv($absolute, 100), 0, '.', ' ');
        if ($absolute % 100 !== 0) {
            $amount .= ','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        }

        return $amount.' '.($money['currency'] === 'KZT' ? '₸' : $money['currency']);
    }

    public function reasonLabel(string $code): string
    {
        return match ($code) {
            'FITMENT_REVIEW_REQUIRED' => $this->say('Совместимость уточнит менеджер', 'Үйлесімділікті менеджер нақтылайды'),
            'UNRESOLVED_ITEM' => $this->say('Нужен подбор менеджера', 'Менеджердің таңдауы керек'),
            'VISIT_RESOURCE_REVIEW_REQUIRED' => $this->say('Выезд согласует менеджер', 'Шығуды менеджер келіседі'),
            'OFFER_UNAVAILABLE' => $this->say('Предложение недоступно', 'Ұсыныс қолжетімсіз'),
            'DELIVERY_REVIEW_REQUIRED' => $this->say('Доставку уточнит менеджер', 'Жеткізуді менеджер нақтылайды'),
            'SERVICE_REVIEW_REQUIRED' => $this->say('Работы уточнит менеджер', 'Жұмысты менеджер нақтылайды'),
            'MULTIPLE_VEHICLES_REVIEW_REQUIRED' => $this->say('Несколько машин — проверит менеджер', 'Бірнеше көлік — менеджер тексереді'),
            default => $this->say('Условия уточнит менеджер', 'Шарттарды менеджер нақтылайды'),
        };
    }

    public function say(string $ru, string $kk): string
    {
        return app()->getLocale() === 'kk' ? $kk : $ru;
    }

    /** @param  list<array<string,mixed>>  $cards */
    private function decorateCheckouts(array $cards): array
    {
        $ids = collect($cards)->map(fn (array $card) => $card['vehicle']['model_id'] ?? null)->filter()->unique()->all();
        $models = $ids === []
            ? collect()
            : CarModel::query()->with('brand')->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(function (array $card) use ($models) {
            if (($card['status'] ?? '') === 'submitted' && ! empty($card['result']['id'])) {
                $order = app(CustomerPurchases::class)->owned(ChatIdentity::buyer(), $card['result']['id']);
                $card['current_order'] = app(CustomerPurchases::class)->view($order);
            }
            $model = $models->get($card['vehicle']['model_id'] ?? null);
            $card['vehicle_label'] = $model
                ? trim($model->brand->name.' '.$model->name)
                : null;
            $card['reason_labels'] = array_values(array_unique(array_map(
                fn ($code) => $this->reasonLabel((string) $code),
                $card['reasons'] ?? [],
            )));

            return $card;
        }, $cards);
    }

    /** @return list<array<string,mixed>> */
    private function latestSearchOffers(Conversation $chat): array
    {
        $this->canRequestManagerSelection = false;
        $this->lastSearchQuery = '';
        $row = DB::table('ai_tool_results')
            ->join('ai_turns', 'ai_turns.id', '=', 'ai_tool_results.turn_id')
            ->where('ai_tool_results.conversation_id', $chat->id)
            ->where('ai_turns.status', 'completed')
            ->where('name', 'search_products')
            ->orderByDesc('ai_tool_results.id')
            ->first(['ai_tool_results.*']);
        if ($row === null) {
            return [];
        }
        try {
            $result = ToolPayload::decode($row->result);
            $args = ToolPayload::decode($row->arguments);
        } catch (\JsonException) {
            return [];
        }
        if (! is_array($result) || isset($result['code'])) {
            return [];
        }
        $items = [];
        foreach ($result['items'] ?? [] as $item) {
            if (! is_array($item) || ! is_numeric($item['offer_id'] ?? null)) {
                continue;
            }
            $item['offer_id'] = (int) $item['offer_id'];
            $items[] = $item;
        }
        $asked = trim((string) (is_array($args) ? ($args['query'] ?? '') : ''));
        $userText = MessageText::decode((string) DB::table('ai_turns as t')
            ->join('ai_messages as m', 'm.id', '=', 't.user_message_id')
            ->where('t.id', $row->turn_id)
            ->value('m.text'));
        $this->lastSearchQuery = trim($userText !== '' ? $userText : $asked);
        $filtered = AssistantSearch::keepRelevant($items, trim($asked.' '.$userText));
        if ($filtered === [] && $this->checkouts === []) {
            $this->canRequestManagerSelection = true;
        }

        return $filtered;
    }

    public function render(): View
    {
        return view('livewire.ai-chat');
    }
}
