<?php

namespace App\Livewire;

use App\Cart\Cart;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\SessionBuyer;
use App\Enums\GlassType;
use App\Enums\OrderSource;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Supplier;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Оптовая витрина: таблица предложений с галочками и количеством.
 *
 * Корзины здесь нет сознательно. Оптовик собирает спецификацию — «вот эти
 * стёкла, вот столько штук» — и отправляет её одной заявкой. Состояние
 * выбора живёт в компоненте, а не в сессии: корзина у оптовика отключена,
 * чтобы два процесса не путались.
 *
 * Отправка — обычным POST на wholesale.store (скрытые поля формы внизу
 * страницы): валидация одна, в StoreWholesaleOrderRequest, а не размазана
 * по компоненту и контроллеру.
 */
class WholesaleTable extends Component
{
    use WithPagination;

    /** Еврокод, OEM, артикул или кусок названия. */
    #[Url(except: '')]
    public string $q = '';

    /** Тип стекла: windshield, rear, side… */
    #[Url(except: '')]
    public string $type = '';

    #[Url(except: false)]
    public bool $inStock = false;

    /** Марка стекла — в базе это поставщик. */
    #[Url(as: 'brand', except: 0)]
    public int $supplierId = 0;

    /** @var array<int,bool> offer_id => выбран */
    public array $checked = [];

    /** @var array<int,int> offer_id => количество */
    public array $qty = [];

    /**
     * «Повторить заявку» из кабинета: /opt?repeat=ID предзаполняет выбор
     * позициями прошлой оптовой заявки. Чужой или розничный заказ молча
     * игнорируем — предзаполнение это подсказка, а не право доступа.
     */
    public function mount(): void
    {
        $repeat = (int) request()->query('repeat', 0);

        if ($repeat <= 0) {
            return;
        }

        $order = Order::query()
            ->whereKey($repeat)
            ->where('customer_id', текущийПокупатель()?->id)
            ->where('source', OrderSource::Wholesale)
            ->with('items')
            ->first();

        if ($order === null) {
            return;
        }

        foreach ($order->items as $item) {
            if ($item->offer_id === null) {
                continue;
            }

            $this->checked[$item->offer_id] = true;
            $this->qty[$item->offer_id] = max(1, (int) $item->quantity);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'type', 'inStock', 'supplierId'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $offerId): void
    {
        if (isset($this->checked[$offerId])) {
            unset($this->checked[$offerId], $this->qty[$offerId]);

            return;
        }

        $this->checked[$offerId] = true;
        $this->qty[$offerId] = 1;
    }

    public function remove(int $offerId): void
    {
        unset($this->checked[$offerId], $this->qty[$offerId]);
    }

    public function setQty(int $offerId, mixed $value): void
    {
        if (! isset($this->checked[$offerId])) {
            return;
        }

        $this->qty[$offerId] = max(1, min(Cart::MAX_WHOLESALE_QUANTITY, (int) $value));
    }

    /** Выбранные предложения с товарами — для панели итога и скрытых полей. */
    public function selection(): Collection
    {
        $ids = array_keys($this->checked);

        if ($ids === []) {
            return collect();
        }

        return Offer::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->with(['product', 'supplier'])
            ->get()
            ->sortBy(fn (Offer $offer) => $offer->product?->name ?? '')
            ->values();
    }

    /** @return array{positions:int,pieces:int,total:float} */
    public function totals(): array
    {
        $customer = текущийПокупатель();
        $total = 0.0;
        $pieces = 0;

        foreach ($this->selection() as $offer) {
            $qty = (int) ($this->qty[$offer->id] ?? 1);
            $pieces += $qty;
            $total += ($offer->sellingPrice($customer) ?? 0) * $qty;
        }

        return [
            'positions' => $this->selection()->count(),
            'pieces' => $pieces,
            'total' => $total,
        ];
    }

    public function render(): View
    {
        $page = max(1, (int) $this->getPage());
        $perPage = 50;
        $params = array_filter([
            'q' => trim($this->q) !== '' ? trim($this->q) : null,
            'page' => $page,
            'per_page' => $perPage,
            'glass_type' => $this->type !== '' ? $this->type : null,
            'supplier_id' => $this->supplierId > 0 ? $this->supplierId : null,
            'in_stock_only' => $this->inStock ?: null,
        ], fn ($value) => $value !== null && $value !== false);

        $result = app(SearchOffers::class)->search(SessionBuyer::current(), $params);
        $ids = array_map(fn (array $item) => (int) $item['offer_id'], $result['items']);
        $offers = $ids === []
            ? collect()
            : Offer::query()
                ->whereIn('id', $ids)
                ->with(['product', 'supplier'])
                ->get()
                ->sortBy(fn (Offer $offer) => array_search($offer->id, $ids, true))
                ->values();

        $paginator = new LengthAwarePaginator(
            $offers,
            $result['meta']['total'],
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
        $paginator->withQueryString();

        return view('livewire.wholesale-table', [
            'offers' => $paginator,
            'types' => GlassType::cases(),
            'suppliers' => Supplier::query()
                ->whereHas('offers', fn ($q) => $q->where('is_active', true)
                    ->whereHas('product', fn ($w) => $w->where('is_published', true)))
                ->orderBy('name')
                ->get(['id', 'name']),
            'customer' => текущийПокупатель(),
            'selection' => $this->selection(),
            'totals' => $this->totals(),
        ]);
    }
}
