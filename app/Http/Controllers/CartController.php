<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Cart\Cart;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly Cart $cart) {}

    public function show(): View
    {
        return view('cart.show', [
            'items' => $this->cart->items(),
            'total' => $this->cart->total(),
            'hasItemsWithoutPrice' => $this->cart->hasItemsWithoutPrice(),
        ]);
    }

    public function add(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_published, 404);

        $quantity = (int) $request->input('quantity', 1);

        $this->cart->add($product, $quantity);

        // С карточки товара возвращаем обратно на неё: покупатель, скорее
        // всего, продолжит смотреть стёкла для той же машины.
        return $request->boolean('go_to_cart')
            ? redirect()->route('cart.show')
            : back()->with('cart_added', $product->name);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->cart->setQuantity($product, (int) $request->input('quantity', 1));

        return back();
    }

    public function remove(Product $product): RedirectResponse
    {
        $this->cart->remove($product);

        return back()->with('cart_removed', true);
    }

    /** Оформление всей корзины одной заявкой. */
    public function checkout(StoreOrderRequest $request, CreateOrder $createOrder): RedirectResponse
    {
        if ($this->cart->isEmpty()) {
            return redirect()->route('cart.show');
        }

        $order = $createOrder->fromCart($request->validated(), $this->cart);

        $this->cart->clear();

        session(['last_order_number' => $order->number]);

        return redirect()->route('order.thanks');
    }
}
