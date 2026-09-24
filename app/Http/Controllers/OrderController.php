<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Actions\StartPayment;
use App\Enums\OrderSource;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(private readonly CreateOrder $createOrder) {}

    /** Заявка из карточки товара. */
    public function store(StoreOrderRequest $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_published, 404);

        $order = $this->createOrder->handle(
            $request->validated(),
            OrderSource::ProductPage,
            $product
        );

        return app(StartPayment::class)->redirectAfterOrder($order);
    }

    /** Заявка на подбор: товар ещё не выбран, клиент описывает машину. */
    public function selection(StoreOrderRequest $request): RedirectResponse
    {
        return app(StartPayment::class)->redirectAfterOrder(
            $this->createOrder->handle($request->validated(), OrderSource::Selection)
        );
    }

    public function form(): View
    {
        return view('order.selection');
    }

    /**
     * Страница «спасибо» показывает только номер заявки и берёт его из сессии.
     *
     * Номера идут подряд, поэтому в адресе им не место: по /order/АС-000124
     * любой желающий листал бы чужие заявки. Кладём в сессию, а не во flash,
     * чтобы страница пережила обновление.
     */
    public function thanks(Request $request): View
    {
        $number = $request->session()->get('last_order_number');

        $order = $number ? Order::where('number', $number)->first() : null;

        return view('order.thanks', [
            'number' => $number,
            'order' => $order,
            'payable' => $order !== null && app(StartPayment::class)->isPayable($order),
            'amount' => $order?->total_amount,
        ]);
    }

    /**
     * Статус заявки по одноразовому токену, а не по номеру АС-000124.
     *
     * Номера идут подряд — по адресу с номером любой желающий листал бы
     * чужие заявки. UUID в ссылке этого не даёт, и ею можно поделиться.
     */
    public function track(Order $order): View
    {
        $order->load(['items', 'assignments.servicePoint']);

        return view('order.track', [
            'order' => $order,
        ]);
    }
}
