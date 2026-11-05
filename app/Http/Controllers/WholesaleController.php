<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\ActiveDraft;
use App\Domain\Ordering\PurchaseCommands;
use App\Http\Requests\StoreWholesaleOrderRequest;
use App\Livewire\WholesaleTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
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

        if (config('ordering.storefront_core')) {
            $buyer = BuyerContext::account($request->user()->id, app()->getLocale());
            $draft = app(ActiveDraft::class)->current($buyer, true)->jsonSerialize();
            $items = collect($draft['items'])->map(fn ($item) => array_intersect_key($item, array_flip(['line_id', 'offer_id', 'reference', 'quantity', 'selection_mode', 'article_confirmed', 'manufacturer_confirmed', 'comment'])))->keyBy(fn ($item) => $item['offer_id'] ? 'offer:'.$item['offer_id'] : 'line:'.$item['line_id'])->all();
            foreach ($positions as $position) {
                $items['offer:'.$position['offer_id']] = ['line_id' => (string) Str::uuid(), 'offer_id' => $position['offer_id'], 'quantity' => $position['qty'], 'selection_mode' => 'exact_article', 'article_confirmed' => false, 'manufacturer_confirmed' => false];
            }
            $customer = $request->user()->customer;
            app(PurchaseCommands::class)->updateDraft($buyer, $draft['id'], 'wholesale-selection:'.($request->validated('form_token') ?: (string) Str::uuid()), $draft['version'], [
                'items' => array_values($items), 'contacts' => ['name' => $customer->name, 'phone' => $customer->phone],
                'fulfillment' => ['type' => $request->validated('fulfillment'), 'city' => $customer->default_city ?: settings()->defaultCity(), 'address' => $request->validated('address')],
            ]);

            session()->forget([WholesaleTable::SESSION_CHECKED, WholesaleTable::SESSION_QTY]);

            return redirect()->route('assistant', ['resume_draft' => $draft['id']]);
        }

        $order = $createOrder->fromWholesaleSelection(
            $request->user()->customer,
            $positions,
            $request->validated(),
        );
        // Заявка ушла — собранный выбор больше не нужен.
        session()->forget([WholesaleTable::SESSION_CHECKED, WholesaleTable::SESSION_QTY]);

        return redirect()
            ->route('account.orders')
            ->with('status', "Оптовая заявка {$order->number} отправлена. Менеджер рассмотрит и предложит оплату: счёт, онлайн или при получении.");
    }
}
