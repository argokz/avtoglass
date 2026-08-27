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

        return $this->thanksRedirect($order);
    }

    /** Заявка на подбор: товар ещё не выбран, клиент описывает машину. */
    public function selection(StoreOrderRequest $request): RedirectResponse
    {
        return $this->thanksRedirect(
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
            // Кнопку оплаты показываем только когда платить действительно есть
            // за что: сумма посчитана, заказ живой и ещё не оплачен.
            'payable' => $order !== null && app(StartPayment::class)->isPayable($order),
            'amount' => $order?->total_amount,
        ]);
    }

    private function thanksRedirect(Order $order): RedirectResponse
    {
        session(['last_order_number' => $order->number]);

        return redirect()->route('order.thanks');
    }
}
