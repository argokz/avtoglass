<?php

namespace App\Actions;

use App\Cart\Cart;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Jobs\AnnounceNewOrder;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Task;
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
     * @param  array{name:string,phone:string,comment?:?string,fulfillment?:?string,address?:?string,car_info?:?string}  $data
     */
    public function handle(array $data, OrderSource $source, ?Product $product = null): Order
    {
        return DB::transaction(function () use ($data, $source, $product) {
            $customer = Customer::forPhone($data['phone'], $data['name']);

            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::New,
                'source' => $source,
                'fulfillment' => $data['fulfillment'] ?? null,
                'address' => $data['address'] ?? null,
                'comment' => $this->buildComment($data),
            ]);

            if ($product !== null) {
                $this->addProduct($order, $product);
                $this->addInstallation($order);
                $order->recalculateTotal();
            }

            $customer->increment('orders_count');

            Interaction::system($order, 'Заявка создана: '.$source->label());
            $this->scheduleCallback($order);
            $this->announce($order);

            return $order;
        });
    }

    /**
     * Задача «перезвонить» создаётся сразу и ничья: менеджера у новой заявки
     * ещё нет, и ждать назначения нельзя — время идёт с момента обращения.
     */
    private function scheduleCallback(Order $order): void
    {
        Task::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'title' => "Перезвонить по заявке {$order->number}",
            'description' => $order->comment,
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
        return DB::transaction(function () use ($data, $cart) {
            $customer = Customer::forPhone($data['phone'], $data['name']);

            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::New,
                'source' => OrderSource::Cart,
                'fulfillment' => $data['fulfillment'] ?? null,
                'address' => $data['address'] ?? null,
                'comment' => $this->buildComment($data),
            ]);

            foreach ($cart->items() as $item) {
                $this->addProduct($order, $item->product, $item->quantity);
            }

            $this->addInstallation($order);
            $order->recalculateTotal();
            $customer->increment('orders_count');

            Interaction::system(
                $order,
                'Заявка создана: корзина, позиций '.$cart->items()->count()
            );

            $this->scheduleCallback($order);
            $this->announce($order);

            return $order;
        });
    }

    /**
     * Позиция сохраняется снимком: прайсы переливаются каждую неделю, карточка
     * может подорожать или уехать с витрины, а в заказе должно остаться то,
     * о чём договорились с клиентом.
     */
    private function addProduct(Order $order, Product $product, int $quantity = 1): void
    {
        $offer = $product->offers()
            ->where('is_active', true)
            ->orderBy('price_retail')
            ->with('supplier')
            ->first();

        $order->items()->create([
            'product_id' => $product->id,
            'offer_id' => $offer?->id,
            'name' => $product->name,
            'supplier_name' => $offer?->supplier?->name,
            'price' => $offer?->price_retail ?? $product->price_min,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Дописывает установку, если клиент выбрал замену в сервисе.
     *
     * Работа становится обычной позицией заказа, а не отдельной сущностью:
     * тогда она сама собой попадает в сумму, в чек, в счёт и в акт — и нигде
     * не приходится помнить «а ещё прибавь установку».
     *
     * Каждому стеклу своя: замена лобового и замена бокового — разные деньги.
     * Считаем по позициям заказа, а не по одной карточке, потому что корзина
     * может содержать и лобовое, и боковое сразу.
     */
    private function addInstallation(Order $order): void
    {
        if ($order->fulfillment !== FulfillmentType::Installation) {
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
    }

    /** Машину клиента дописываем к комментарию: отдельного поля под неё в заявке нет. */
    private function buildComment(array $data): ?string
    {
        $parts = array_filter([
            trim((string) ($data['comment'] ?? '')) ?: null,
            ! empty($data['car_info']) ? 'Автомобиль: '.trim($data['car_info']) : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }
}
