<?php

namespace App\Livewire;

use App\Cart\Cart;
use App\Domain\Catalog\Availability;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\SessionBuyer;
use App\Enums\GlassType;
use App\Enums\OrderSource;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\WholesaleList;
use App\Notifications\WhatsAppClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
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

    /** Больше строк за раз — это уже импорт файла, а не вставка. */
    public const MAX_PASTED_LINES = 200;

    /** Ключи сессии выбора — контроллер очищает их после отправки заявки. */
    public const SESSION_CHECKED = 'wholesale.checked';

    public const SESSION_QTY = 'wholesale.qty';

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

    /**
     * Выбор живёт в сессии: оптовик собирает заявку полчаса, отвлекается
     * на звонок, уходит в кабинет — и не должен начинать заново.
     *
     * @var array<int,bool> offer_id => выбран
     */
    #[Session(key: self::SESSION_CHECKED)]
    public array $checked = [];

    /** @var array<int,int> offer_id => количество */
    #[Session(key: self::SESSION_QTY)]
    public array $qty = [];

    /** Вставленный список: «еврокод количество» построчно. */
    public string $pasted = '';

    /**
     * Строки списка, где по коду нашлось несколько марок стекла: выбирает
     * человек. Подставлять «самое дешёвое» молча нельзя — у установщика
     * клиент мог просить именно AGC.
     *
     * @var list<array{code:string,qty:int,offers:list<array{id:int,label:string}>}>
     */
    #[Locked]
    public array $ambiguous = [];

    /** @var list<string> коды, которых нет в каталоге */
    #[Locked]
    public array $notFound = [];

    public string $listName = '';

    /** Короткий итог последнего действия: «Отмечено позиций: 7». */
    public string $flash = '';

    /**
     * «Повторить заявку» из кабинета: /opt?repeat=ID предзаполняет выбор
     * позициями прошлой оптовой заявки. Чужой или розничный заказ молча
     * игнорируем — предзаполнение это подсказка, а не право доступа.
     */
    public function mount(): void
    {
        // Ссылка из напоминания о регулярной заявке: /opt?list=ID.
        $list = (int) request()->query('list', 0);
        if ($list > 0) {
            $this->loadList($list);
        }

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

    public function clearSelection(): void
    {
        $this->checked = $this->qty = [];
    }

    /**
     * Быстрый ввод: вставили столбец из Excel или WhatsApp — «2001AGNMVZ 3»,
     * «2001AGNMVZ;3», «2001AGNMVZ x3». Однозначные коды сразу отмечаются,
     * спорные и ненайденные показываются отдельно.
     */
    public function addPasted(): void
    {
        $this->validate(['pasted' => ['required', 'string', 'max:20000']], ['pasted.required' => 'Вставьте хотя бы одну строку']);
        $buyer = SessionBuyer::current();
        $this->ambiguous = $this->notFound = [];
        $added = 0;

        foreach (array_slice(self::parseLines($this->pasted), 0, self::MAX_PASTED_LINES) as [$code, $quantity]) {
            $result = app(SearchOffers::class)->search($buyer, ['q' => $code, 'per_page' => 10]);
            $exact = array_values(array_filter($result['items'], fn (array $item) => ($item['match']['type'] ?? '') === 'exact_code'));

            if ($exact === []) {
                $this->notFound[] = $code;
            } elseif (count($exact) === 1) {
                $this->pick((int) $exact[0]['offer_id'], $quantity);
                $added++;
            } else {
                $this->ambiguous[] = ['code' => $code, 'qty' => $quantity, 'offers' => array_map(fn (array $item) => [
                    'id' => (int) $item['offer_id'],
                    'label' => trim(($item['manufacturer']['name'] ?? '').' · '.($item['name'] ?? '')
                        .' · '.Availability::fromArray($item['availability'] ?? null)->short()),
                ], $exact)];
            }
        }

        $this->pasted = '';
        $this->flash = "Отмечено позиций: {$added}";
    }

    public function chooseAmbiguous(int $index, int $offerId): void
    {
        $line = $this->ambiguous[$index] ?? null;
        if ($line === null || ! in_array($offerId, array_column($line['offers'], 'id'), true)) {
            return;
        }
        $this->pick($offerId, $line['qty']);
        unset($this->ambiguous[$index]);
        $this->ambiguous = array_values($this->ambiguous);
    }

    /** @return list<array{0:string,1:int}> */
    public static function parseLines(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Код — первое «слово», количество — последнее число в строке.
            $parts = preg_split('/\s*[;,\t]\s*|\s+[xх×*]\s*|\s+/u', $line) ?: [];
            $code = SearchOffers::normalizeCode((string) array_shift($parts));
            $last = end($parts);
            $quantity = $last !== false && preg_match('/^\d{1,3}$/', $last) ? (int) $last : 1;
            if ($code !== '') {
                $rows[] = [$code, max(1, min(Cart::MAX_WHOLESALE_QUANTITY, $quantity))];
            }
        }

        return $rows;
    }

    private function pick(int $offerId, int $quantity): void
    {
        $this->checked[$offerId] = true;
        $this->qty[$offerId] = max(1, min(Cart::MAX_WHOLESALE_QUANTITY, ($this->qty[$offerId] ?? 0) + $quantity));
    }

    public function saveList(): void
    {
        $customer = текущийПокупатель();
        $this->validate(['listName' => ['required', 'string', 'max:80']], ['listName.required' => 'Назовите список, например «Еженедельная закупка»']);
        if ($customer === null || $this->checked === []) {
            return;
        }
        $exists = WholesaleList::query()->where('customer_id', $customer->id)->where('name', trim($this->listName))->exists();
        if (! $exists && WholesaleList::query()->where('customer_id', $customer->id)->count() >= WholesaleList::MAX_PER_CUSTOMER) {
            $this->addError('listName', 'Списков уже '.WholesaleList::MAX_PER_CUSTOMER.' — удалите ненужный');

            return;
        }
        WholesaleList::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'name' => trim($this->listName)],
            ['items' => collect(array_keys($this->checked))->map(fn ($id) => ['offer_id' => (int) $id, 'qty' => (int) ($this->qty[$id] ?? 1)])->values()->all()],
        );
        $this->listName = '';
        $this->flash = 'Список сохранён';
    }

    /** Загрузка списка добавляет к выбору, а не заменяет его: так собирают заявку из двух списков. */
    public function loadList(int $id): void
    {
        $list = WholesaleList::query()->whereKey($id)->where('customer_id', текущийПокупатель()?->id)->first();
        if ($list === null) {
            return;
        }
        $active = Offer::query()->whereIn('id', array_column($list->items, 'offer_id'))->where('is_active', true)->pluck('id')->all();
        foreach ($list->items as $row) {
            if (in_array((int) $row['offer_id'], $active, true)) {
                $this->checked[(int) $row['offer_id']] = true;
                $this->qty[(int) $row['offer_id']] = max(1, min(Cart::MAX_WHOLESALE_QUANTITY, (int) $row['qty']));
            }
        }
        $gone = count($list->items) - count($active);
        $this->flash = $gone > 0 ? "Список «{$list->name}» загружен; {$gone} поз. больше не продаётся" : "Список «{$list->name}» загружен";
    }

    /** День регулярной заявки: 1–7 по ISO, 0 — не напоминать. */
    public function setReminder(int $id, mixed $weekday): void
    {
        $weekday = (int) $weekday;
        if (! array_key_exists($weekday, WholesaleList::WEEKDAYS) && $weekday !== 0) {
            return;
        }
        $list = WholesaleList::query()->whereKey($id)->where('customer_id', текущийПокупатель()?->id)->first();
        if ($list === null) {
            return;
        }
        $list->forceFill(['remind_weekday' => $weekday ?: null])->save();
        $this->flash = $weekday
            ? "«{$list->name}» — регулярная заявка ".$list->scheduleLabel()
            : "Напоминание для «{$list->name}» отключено";
    }

    public function deleteList(int $id): void
    {
        WholesaleList::query()->whereKey($id)->where('customer_id', текущийПокупатель()?->id)->delete();
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

    /**
     * @param  Collection<int,Offer>|null  $selected
     * @return array{positions:int,pieces:int,total:float}
     */
    public function totals(?Collection $selected = null): array
    {
        $selected ??= $this->selection();
        $customer = текущийПокупатель();
        $total = 0.0;
        $pieces = 0;

        foreach ($selected as $offer) {
            $qty = (int) ($this->qty[$offer->id] ?? 1);
            $pieces += $qty;
            $total += ($offer->sellingPrice($customer) ?? 0) * $qty;
        }

        return [
            'positions' => $selected->count(),
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

        $selection = $this->selection();

        $customer = текущийПокупатель();
        $lists = $customer
            ? WholesaleList::query()->where('customer_id', $customer->id)->orderBy('name')->get(['id', 'name', 'items', 'remind_weekday'])
            : collect();

        return view('livewire.wholesale-table', [
            'offers' => $paginator,
            // Срок уже посчитан поиском вместе с резервами — второй раз за остатком не ходим.
            'availability' => array_column($result['items'], 'availability', 'offer_id'),
            'lists' => $lists,
            'dueToday' => $lists->filter(fn (WholesaleList $list) => $list->isDueOn(CarbonImmutable::now()))->values(),
            'canRemind' => app(WhatsAppClient::class)->canSend(),
            'recent' => $customer ? Order::query()->where('customer_id', $customer->id)->where('source', OrderSource::Wholesale)
                ->withCount('items')->latest()->orderByDesc('id')->limit(5)->get(['id', 'number', 'created_at', 'total_amount', 'status']) : collect(),
            'types' => GlassType::cases(),
            'suppliers' => Supplier::query()
                ->whereHas('offers', fn ($q) => $q->where('is_active', true)
                    ->whereHas('product', fn ($w) => $w->where('is_published', true)))
                ->orderBy('name')
                ->get(['id', 'name']),
            'customer' => текущийПокупатель(),
            'selection' => $selection,
            'totals' => $this->totals($selection),
        ]);
    }
}
