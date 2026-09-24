<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Http\Requests\StoreWholesaleOrderRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Оптовая заявка-спецификация.
 *
 * Витрина /opt — таблица с галочками (Livewire). Оплаты при отправке нет:
 * счёт, онлайн или при получении — менеджер решает после рассмотрения.
 */
class WholesaleController extends Controller
{
    /**
     * Оптовая витрина. OnlyWholesale здесь нет нарочно: розничному клиенту и
     * «заявке на проверке» показываем понятный экран, а не 403.
     */
    public function index(): View
    {
        return view('wholesale.index', [
            'customer' => auth()->user()->customer,
        ]);
    }

    public function store(StoreWholesaleOrderRequest $request, CreateOrder $createOrder): RedirectResponse
    {
        $positions = collect($request->validated('items'))
            ->map(fn (array $row) => ['offer_id' => (int) $row['offer_id'], 'qty' => (int) $row['qty']])
            ->values()
            ->all();

        $order = $createOrder->fromWholesaleSelection(
            $request->user()->customer,
            $positions,
            $request->validated(),
        );

        return redirect()
            ->route('account.orders')
            ->with('status', "Оптовая заявка {$order->number} отправлена. Менеджер рассмотрит и предложит оплату: счёт, онлайн или при получении.");
    }
}
