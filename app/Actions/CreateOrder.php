<?php

namespace App\Actions;

use App\Cart\Cart;
use App\Catalog\Vin;
use App\Domain\Risk\AssessOrderRisk;
use App\Domain\Risk\RiskLevel;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Jobs\AnnounceNewOrder;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Создание заявки. Через это действие проходят все каналы — форма в карточке,
 * заявка на подбор, ручное заведение менеджером, — чтобы правила о снимке цены
 * и поиске клиента по телефону не расползлись по контроллерам.
 */
class CreateOrder
{
    /**
     * Через сколько минут менеджер должен перезвонить по заявке с сайта.
     *
     * Число маленькое намеренно: в этой нише у клиента разбито стекло и он
     * пишет сразу в несколько мест. Кто перезвонил первым, тот и продал,
     * поэтому задача ставится на ближайшие минуты, а не на «сегодня».
     */
    private const CALLBACK_MINUTES = 15;

    /**
     * @param  array{name:string,phone:string,comment?:?string,fulfillment?:?string,payment_method?:?string,address?:?string,car_info?:?string,preferred_date?:?string,preferred_time?:?string,form_token?:?string}  $data
     */
    public function handle(array $data, OrderSource $source, ?Product $product = null): Order
    {
        // Бронь сама защищает слот; вернуть старый заказ туда нельзя —
        // поверх него BookInstallation вешает наряд.
        if ($source !== OrderSource::Booking) {
            $existing = $this->findByFormToken($data['form_token'] ?? null);

            if ($existing !== null) {
                return $this->replay($existing);
            }
        }

        return DB::transaction(function () use ($data, $source, $product) {
            $customer = Customer::forPhone($data['phone'], $data['name']);

            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::New,
                'source' => $source,
                'fulfillment' => $data['fulfillment'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? currentCity(),
                'comment' => $this->buildComment($data, $customer),
                'privacy_accepted_at' => $this->privacyAcceptedAt($data),
                'form_token' => $this->formToken($data),
            ]);

            $this->rememberVin($customer);
            $this->rememberDelivery($customer, $data);
            $this->linkAccount($customer);

            if ($product !== null) {
                $this->addProduct($order, $product);
                $this->addWorks($order);
                $order->recalculateTotal();
            }

            $customer->increment('orders_count');

            Interaction::system($order, 'Заявка создана: '.$source->label());
            $this->assessAndFollowUp($order);

            return $order;
        });
    }

    /**
     * Антифрод и дальнейшие действия по свежей заявке.
     *
     * Заблокированная заявка остаётся в базе, но смена о ней не узнаёт:
     * ни оповещения, ни задачи на перезвон. Помеченная к проверке живёт
     * обычной жизнью плюс задача «проверить до звонка».
     */
    private function assessAndFollowUp(Order $order): void
    {
        $risk = app(AssessOrderRisk::class)->apply($order, request()->ip());

        if ($risk === RiskLevel::Block) {
            return;
        }

        $this->scheduleCallback($order);
        $this->announce($order);
    }

    /**
     * Задача «перезвонить» создаётся сразу и ничья: менеджера у новой заявки
     * ещё нет, и ждать назначения нельзя — время идёт с момента обращения.
     */
    private function scheduleCallback(Order $order): void
    {
        $booking = $order->source === OrderSource::Booking;

        Task::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'title' => $booking
                ? "Подтвердить запись {$order->number}"
                : "Перезвонить по заявке {$order->number}",
            'description' => $booking
                ? 'Клиент уже выбрал время. Позвонить, чтобы подтвердить наличие стекла.'
                : $order->comment,
            'due_at' => now()->addMinutes(self::CALLBACK_MINUTES),
        ]);
    }

    /**
     * Сигнал смене о новой заявке.
     *
     * afterCommit обязателен: без него воркер может схватить задачу раньше,
     * чем транзакция закоммитится, и уйти в телеграм с сообщением о заявке,
     * которой в базе ещё нет.
     */
    private function announce(Order $order): void
    {
        AnnounceNewOrder::dispatch($order)->afterCommit();
    }

    /**
     * Заявка на всю корзину.
     *
     * Отдельный вход, а не цикл вокруг handle(): корзина — это одна заявка
     * с несколькими позициями, а не несколько заявок. Иначе менеджер получит
     * пять звонков одному клиенту, а клиент — пять номеров заказа.
     */
    public function fromCart(array $data, Cart $cart): Order
    {
        $existing = $this->findByFormToken($data['form_token'] ?? null);

        if ($existing !== null) {
            return $this->replay($existing);
        }

        return DB::transaction(function () use ($data, $cart) {
            $customer = Customer::forPhone($data['phone'], $data['name']);

            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::New,
                'source' => OrderSource::Cart,
                'fulfillment' => $data['fulfillment'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? currentCity(),
                'comment' => $this->buildComment($data, $customer),
                'privacy_accepted_at' => $this->privacyAcceptedAt($data),
                'form_token' => $this->formToken($data),
            ]);

            $this->rememberVin($customer);
            $this->rememberDelivery($customer, $data);
            $this->linkAccount($customer);

            foreach ($cart->items() as $item) {
                $this->addProduct($order, $item->product, $item->quantity, $item->offer);
            }

            $this->addWorks($order);
            $order->recalculateTotal();
            $customer->increment('orders_count');

            Interaction::system(
                $order,
                'Заявка создана: корзина, позиций '.$cart->items()->count()
            );

            $this->assessAndFollowUp($order);

            return $order;
        });
    }

    /**
     * Оптовая заявка-спецификация: много позиций одним списком, без корзины.
     *
     * От корзины отличается тремя вещами: клиент уже известен (это вошедший
     * оптовик), установки нет (оптовик ставит сам) и оплаты при отправке нет —
     * счёт, онлайн или при получении менеджер предложит после рассмотрения.
     * Поэтому и задача не «перезвонить за 15 минут», а «рассмотреть за час».
     *
     * @param  array<int,array{offer_id:int,qty:int}>  $positions
     * @param  array{fulfillment?:?string,address?:?string,city?:?string,comment?:?string,form_token?:?string,privacy_consent?:?string}  $data
     */
    public function fromWholesaleSelection(Customer $customer, array $positions, array $data): Order
    {
        $existing = $this->findByFormToken($data['form_token'] ?? null);

        if ($existing !== null) {
            return $this->replay($existing);
        }

        return DB::transaction(function () use ($customer, $positions, $data) {
            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::New,
                'source' => OrderSource::Wholesale,
                'fulfillment' => $data['fulfillment'] ?? FulfillmentType::Pickup->value,
                'payment_method' => null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? currentCity(),
                'comment' => $this->buildComment($data, $customer),
                'privacy_accepted_at' => $this->privacyAcceptedAt($data),
                'form_token' => $this->formToken($data),
            ]);

            $offers = Offer::query()
                ->whereIn('id', array_column($positions, 'offer_id'))
                ->where('is_active', true)
                ->with(['product', 'supplier'])
                ->get()
                ->keyBy('id');

            $count = 0;

            foreach ($positions as $position) {
                $offer = $offers->get((int) $position['offer_id']);

                if ($offer === null || $offer->product === null) {
                    continue;
                }

                $order->items()->create([
                    'product_id' => $offer->product_id,
                    'offer_id' => $offer->id,
                    'name' => $offer->product->name,
                    'supplier_name' => $offer->supplier?->name,
                    'price' => $offer->sellingPrice($customer),
                    'quantity' => max(1, min(Cart::MAX_WHOLESALE_QUANTITY, (int) $position['qty'])),
                ]);
                $count++;
            }

            $order->recalculateTotal();
            $customer->increment('orders_count');
            $this->rememberDelivery($customer, $data);
            $this->linkAccount($customer);

            Interaction::system($order, "Оптовая заявка создана: позиций {$count}");

            Task::create([
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'title' => "Оптовая заявка {$order->number}",
                'description' => trim(implode("\n", array_filter([
                    "Позиций: {$count}. Рассмотреть и предложить оплату: счёт, онлайн или при получении.",
                    $customer->company_name ? 'Организация: '.$customer->company_name : null,
                    $customer->bin ? 'БИН/ИИН: '.$customer->bin : null,
                ]))),
                'due_at' => now()->addHour(),
            ]);

            $this->announce($order);

            return $order;
        });
    }

    /**
     * Повтор той же отправки формы: F5, «назад», двойной клик.
     * Задачу и оповещение не дублируем — менеджер уже получил первую.
     */
    private function replay(Order $order): Order
    {
        Interaction::system($order, 'Повторная отправка той же формы — дубль не создан');
        session()->flash('order_deduped', true);

        return $order;
    }

    private function findByFormToken(?string $token): ?Order
    {
        $token = $this->formToken(['form_token' => $token]);

        if ($token === null) {
            return null;
        }

        return Order::where('form_token', $token)->first();
    }

    private function formToken(array $data): ?string
    {
        $token = trim((string) ($data['form_token'] ?? ''));

        return $token !== '' ? $token : null;
    }

    /**
     * Позиция сохраняется снимком: прайсы переливаются каждую неделю, карточка
     * может подорожать или уехать с витрины, а в заказе должно остаться то,
     * о чём договорились с клиентом.
     */
    private function addProduct(Order $order, Product $product, int $quantity = 1, ?Offer $offer = null): void
    {
        if ($offer === null) {
            $offer = $product->offers()
                ->where('is_active', true)
                ->orderBy('price_retail')
                ->with('supplier')
                ->first();
        } else {
            $offer->loadMissing('supplier');
        }

        $order->items()->create([
            'product_id' => $product->id,
            'offer_id' => $offer?->id,
            'name' => $product->name,
            'supplier_name' => $offer?->supplier?->name,
            'price' => $offer?->sellingPrice($order->customer) ?? $product->sellingPriceMin($order->customer),
            'quantity' => $quantity,
        ]);
    }

    /**
     * Дописывает работы: установку в сервисе или с выездом.
     *
     * Работа становится обычной позицией заказа, а не отдельной сущностью:
     * тогда она сама собой попадает в сумму, в чек, в счёт и в акт — и нигде
     * не приходится помнить «а ещё прибавь установку».
     *
     * Каждому стеклу своя: замена лобового и замена бокового — разные деньги.
     * Считаем по позициям заказа, а не по одной карточке, потому что корзина
     * может содержать и лобовое, и боковое сразу.
     *
     * Выезд — одна позиция на заявку: мастер едет один раз, сколько бы
     * стёкол ни ставил.
     */
    private function addWorks(Order $order): void
    {
        if (! $order->fulfillment?->includesInstallation()) {
            return;
        }

        foreach ($order->items()->whereNotNull('product_id')->with('product')->get() as $item) {
            $service = Service::defaultFor($item->product?->glass_type);

            if ($service === null) {
                continue;
            }

            $order->items()->create([
                ...$service->toOrderItem(),
                'quantity' => $item->quantity,
            ]);
        }

        if ($order->fulfillment !== FulfillmentType::Visit) {
            return;
        }

        $выезд = Service::visitFee();

        if ($выезд === null) {
            return;
        }

        $order->items()->create($выезд->toOrderItem());
    }

    /** Машину клиента, организацию и желаемое время установки дописываем к комментарию */
    private function buildComment(array $data, ?Customer $customer = null): ?string
    {
        $preferredSlot = null;
        if (! empty($data['preferred_date'])) {
            $slotTime = ! empty($data['preferred_time']) ? " ({$data['preferred_time']})" : '';
            $preferredSlot = "Желаемая дата/время записи (требует подтверждения менеджера): {$data['preferred_date']}{$slotTime}";
        }

        $company = null;
        if ($customer?->company_name || $customer?->seesWholesalePrices()) {
            $company = implode(', ', array_filter([
                $customer->company_name ? 'Организация: '.$customer->company_name : null,
                $customer->bin ? 'БИН/ИИН: '.$customer->bin : null,
                $customer->seesWholesalePrices()
                    ? 'опт, скидка '.((int) ($customer->wholesale_discount_percent ?? 0)).'%'
                    : null,
            ]));
        }

        $parts = array_filter([
            trim((string) ($data['comment'] ?? '')) ?: null,
            $preferredSlot,
            ! empty($data['car_info']) ? 'Автомобиль: '.trim($data['car_info']) : null,
            $company,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }

    /**
     * VIN из подбора кладём клиенту, а не в комментарий и не в адрес.
     *
     * В сессии он уже есть — чтобы не набирать семнадцать знаков второй раз.
     * В карточке клиента он нужен менеджеру при следующем обращении.
     */
    private function rememberVin(Customer $customer): void
    {
        $vin = session('vin');

        if (! is_string($vin) || $vin === '') {
            return;
        }

        $parsed = Vin::parse($vin);

        if ($parsed === null) {
            return;
        }

        $customer->update(['vin' => $parsed->value]);
    }

    /** Адрес и способ получения — для следующей заявки вошедшего клиента. */
    private function rememberDelivery(Customer $customer, array $data): void
    {
        $customer->rememberDelivery(
            $data['address'] ?? null,
            $data['city'] ?? currentCity(),
            $data['fulfillment'] ?? null,
        );
    }

    /**
     * Вошедшему покупателю с тем же телефоном привязываем карточку сразу.
     *
     * Без этого новый заказ «висит» отдельно от кабинета, пока человек
     * не пройдёт claim. Регистрация на чужой номер по-прежнему безопасна:
     * здесь телефон уже подтверждён паролем сессии.
     */
    private function linkAccount(Customer $customer): void
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->isStaff()) {
            return;
        }

        if ($user->customer_id !== null) {
            return;
        }

        if ($user->role !== UserRole::Customer) {
            return;
        }

        if ($user->phone !== $customer->phone) {
            return;
        }

        $user->forceFill(['customer_id' => $customer->id])->save();
    }

    private function privacyAcceptedAt(array $data): ?Carbon
    {
        if (empty($data['privacy_consent'])) {
            return null;
        }

        return now();
    }
}
