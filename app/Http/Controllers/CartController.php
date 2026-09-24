<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Actions\StartPayment;
use App\Cart\Cart;
use App\Enums\FulfillmentType;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly Cart $cart) {}

    public function show(): View
    {
        abort_unless(settings()->enabled('cart'), 404);

        $quotes = collect($this->cart->quotes())
            ->map(fn ($quote) => $quote->toArray())
            ->all();

        $defaultFulfillment = FulfillmentType::Installation->value;

        if (auth()->check() && ! auth()->user()->isStaff()) {
            $pref = auth()->user()->customer?->preferred_fulfillment;
            $defaultFulfillment = $pref instanceof FulfillmentType
                ? $pref->value
                : (FulfillmentType::tryFrom((string) ($pref ?? ''))?->value ?? FulfillmentType::Installation->value);
        }

        $items = $this->cart->items();
        $items->each(fn ($item) => $item->product->loadMissing('fitments.carModel.brand'));

        return view('cart.show', [
            'items' => $items,
            'total' => $this->cart->total(),
            'hasItemsWithoutPrice' => $this->cart->hasItemsWithoutPrice(),
            'quotes' => $quotes,
            'defaultFulfillment' => $defaultFulfillment,
            'carLabel' => $this->carLabel($items),
        ]);
    }

    /** Подпись машины для формы: менеджер и клиент видят одно и то же. */
    private function carLabel(Collection $items): ?string
    {
        $labels = $items
            ->flatMap(fn ($item) => $item->product->fitments)
            ->map(function ($fitment) {
                $model = $fitment->carModel;
                $brand = $model?->brand?->name;
                $name = $model?->name;

                return ($brand && $name) ? "{$brand} {$name}" : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return null;
        }

        return $labels->take(3)->implode(', ');
    }

    public function add(Request $request, Product $product): RedirectResponse
    {
        abort_unless(settings()->enabled('cart'), 404);
        abort_unless($product->is_published, 404);

        $quantity = (int) $request->input('quantity', 1);
        $offerId = (int) $request->input('offer_id') ?: null;

        $this->cart->add($product, $quantity, $offerId);

        // Быстрый заказ — сразу к оформлению. «В корзину» — остаёмся на
        // карточке: человек обычно смотрит ещё стёкла для той же машины.
        if ($request->boolean('go_to_cart')) {
            return redirect()
                ->route('cart.show')
                ->withFragment('oformlenie')
                ->with('quick_checkout', true);
        }

        return back()->with('cart_added', $product->name);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->cart->setQuantity(
            $product,
            (int) $request->input('quantity', 1),
            (int) $request->input('offer_id') ?: null,
        );

        return back();
    }

    public function remove(Request $request, Product $product): RedirectResponse
    {
        $this->cart->remove($product, (int) $request->input('offer_id') ?: null);

        return back()->with('cart_removed', true);
    }

    /** Оформление всей корзины одной заявкой. */
    public function checkout(StoreOrderRequest $request, CreateOrder $createOrder): RedirectResponse
    {
        abort_unless(settings()->enabled('cart'), 404);

        if ($this->cart->isEmpty()) {
            return redirect()->route('cart.show');
        }

        $order = $createOrder->fromCart($request->validated(), $this->cart);

        $this->cart->clear();

        return app(StartPayment::class)->redirectAfterOrder($order);
    }
}
