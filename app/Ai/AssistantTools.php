<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Catalog\OfferVehicleConstraints;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\BuyerContext;
use App\Domain\Identity\SessionBuyer;
use App\Domain\Pricing\Money;
use App\Domain\Shared\PurchaseException;
use App\Enums\FulfillmentType;
use App\Enums\GlassType;
use App\Models\Product;
use App\Models\ServicePoint;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Model tools only read catalogue facts and prepare a reviewable checkout. */
class AssistantTools
{
    public function __construct(private readonly SearchOffers $search, private readonly AssistantCheckout $checkout) {}

    public static function vehicleProperties(): array
    {
        return [
            'car_model_id' => ['type' => 'integer', 'description' => 'Точный ID модели только из данных каталога; не угадывать'],
            'brand_id' => ['type' => 'integer'],
            'year' => ['type' => 'integer'],
            'generation_id' => ['type' => 'integer', 'description' => 'ID поколения только из каталога'],
            'body' => ['type' => 'string'],
            'doors' => ['type' => 'string'],
            'options' => ['type' => 'object', 'properties' => array_map(
                fn (string $label) => ['type' => 'boolean', 'description' => $label.'; указывать только если клиент сообщил'],
                Product::OPTION_FLAGS,
            )],
        ];
    }

    public static function declarations(): array
    {
        return [
            ...JourneyTools::declarations(),
            [
                'name' => 'search_products',
                'description' => 'Поиск предложений автостёкол по модели, еврокоду, OEM, артикулу и параметрам автомобиля. Цены/наличие брать только отсюда. Совпадение артикула не доказывает применимость. Неизвестные опции уточнить по missing_options.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Модель авто, еврокод, OEM или артикул'],
                    'glass_type' => ['type' => 'string', 'enum' => array_column(GlassType::cases(), 'value')],
                    ...self::vehicleProperties(),
                ]],
            ],
            [
                'name' => 'prepare_order',
                'description' => 'Подготовить расчёт для проверки клиентом. Заказ НЕ создаётся: окончательное согласие и отправка только кнопкой в интерфейсе. Использовать offer_id конкретного предложения, а если ничего не найдено — reference с описанием подбора. Никогда не утверждать, что заявка уже отправлена.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'offer_id' => ['type' => 'integer', 'description' => 'offer_id из search_products, не product_id'],
                    'reference' => ['type' => 'string', 'description' => 'Описание нужного стекла для заявки на подбор без offer_id'],
                    'quantity' => ['type' => 'integer'],
                    'items' => [
                        'type' => 'array',
                        'description' => 'Список позиций для оптовой или сборной заявки',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'offer_id' => ['type' => 'integer'],
                                'reference' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer'],
                                'comment' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'fulfillment' => ['type' => 'string', 'enum' => array_column(FulfillmentType::cases(), 'value')],
                    'city' => ['type' => 'string'],
                    'address' => ['type' => 'string'],
                    'service_point_id' => ['type' => 'integer', 'description' => 'Пункт из service_points результатов поиска'],
                    'slot_start' => ['type' => 'string', 'description' => 'Для установки/выезда: согласованное с клиентом начало, ISO 8601 с часовым поясом, например 2026-09-21T10:00:00+05:00. Не угадывать время'],
                    'slot_end' => ['type' => 'string', 'description' => 'Для установки/выезда: конец того же интервала, ISO 8601 с часовым поясом. Требуется вместе с slot_start'],
                    'comment' => ['type' => 'string'],
                    ...self::vehicleProperties(),
                ], 'required' => ['name', 'phone']],
            ],
        ];
    }

    public function call(string $name, array $args, ?BuyerContext $buyer = null, ?string $turnId = null): array
    {
        try {
            return match ($name) {
                'search_products' => $this->searchProducts($args, $buyer),
                'prepare_order' => $this->checkout->prepare($buyer ?? SessionBuyer::current(), $args, $turnId),
                default => in_array($name, array_column(JourneyTools::declarations(), 'name'), true)
                    ? app(JourneyTools::class)->call($name, $args, $buyer ?? SessionBuyer::current(), $turnId)
                    : ['error' => 'Неизвестный инструмент. Для оформления используйте prepare_order и подтверждение кнопкой.', 'code' => 'UNKNOWN_TOOL'],
            };
        } catch (ValidationException $exception) {
            return ['error' => 'Уточните некорректные или недостающие поля.', 'code' => 'VALIDATION_FAILED', 'details' => $exception->errors()];
        } catch (PurchaseException $exception) {
            return ['error' => 'Расчёт не удалось подготовить. Уточните данные или начните новый расчёт.', 'code' => $exception->errorCode, 'details' => $exception->details];
        }
    }

    public function searchProducts(array $args, ?BuyerContext $buyer = null): array
    {
        $buyer ??= SessionBuyer::current();
        Validator::make($args, [
            'query' => ['nullable', 'string', 'max:200'],
            'options' => ['sometimes', 'array:'.implode(',', array_keys(Product::OPTION_FLAGS))],
            'options.*' => [fn ($attribute, $value, $fail) => is_bool($value) || $value === null ? null : $fail('Ожидается true/false; неизвестную опцию не передавайте.')],
        ])->validate();
        $question = trim((string) ($args['query'] ?? ''));
        $references = AssistantSearch::references($question);
        $lineQuantities = AssistantSearch::lineQuantities($question);
        $args = AssistantSearch::parameters($args);
        $term = trim((string) ($args['query'] ?? ''));
        if ($term === '' && empty($args['car_model_id'])) {
            return ['items' => [], 'note' => 'Уточните модель авто или еврокод.'];
        }

        $params = array_intersect_key($args, array_flip(['glass_type', ...array_keys(self::vehicleProperties())]));
        if (count($references) > 1) {
            $rawItems = [];
            $seenOffers = [];
            $vehicle = [];
            $anyHasMore = false;
            foreach ($references as $ref) {
                $part = $this->search->search($buyer, ['q' => $ref, 'page' => 1, 'per_page' => 100, ...$params]);
                $vehicle = $vehicle !== [] ? $vehicle : ($part['vehicle'] ?? []);
                $anyHasMore = $anyHasMore || ($part['meta']['has_more'] ?? false);
                foreach ($part['items'] as $row) {
                    $offerId = (int) ($row['offer_id'] ?? 0);
                    if ($offerId > 0 && ! isset($seenOffers[$offerId]) && OfferVehicleConstraints::allows($row, $part['vehicle'] ?? [])) {
                        $seenOffers[$offerId] = true;
                        $rawItems[] = $row;
                    }
                }
            }
            $candidates = $rawItems;
            $result = ['vehicle' => $vehicle, 'meta' => ['has_more' => $anyHasMore]];
        } else {
            $result = $this->search->search($buyer, ['q' => $term, 'page' => 1, 'per_page' => 100, ...$params]);
            $candidates = array_values(array_filter($result['items'], fn ($row) => OfferVehicleConstraints::allows($row, $result['vehicle'])));
        }

        $items = array_map(function (array $row) use ($lineQuantities): array {
            $price = $row['unit_price']['amount_minor'] ?? null;
            $articleKey = mb_strtoupper((string) ($row['article'] ?? ''));
            $eurocodeKey = mb_strtoupper((string) ($row['eurocode'] ?? ''));
            $requestedQty = $lineQuantities[$articleKey] ?? $lineQuantities[$eurocodeKey] ?? null;

            return [
                ...$row,
                'id' => $row['product_id'],
                'type' => GlassType::tryFrom((string) ($row['glass_type'] ?? ''))?->label(),
                'price_kzt' => is_int($price) ? Money::fromMinor($price)->decimal() : null,
                'stock' => $row['available_qty'] > 0 ? 'в наличии' : 'под заказ',
                'missing_options' => $row['fitment']['missing_options'] ?? [],
                'url' => ! empty($row['product_slug']) ? route('catalog.product', ['product' => $row['product_slug']]) : null,
                ...($requestedQty !== null ? ['requested_qty' => $requestedQty] : []),
            ];
        }, $candidates);
        $items = AssistantSearch::keepRelevant($items, $question !== '' ? $question : $term);
        $hasMore = count($items) > 8 || $result['meta']['has_more'];
        $items = array_slice($items, 0, 8);

        return [
            'items' => $items,
            'meta' => ['total' => count($items), 'has_more' => $hasMore],
            'vehicle' => $result['vehicle'] ?? [],
            'service_points' => ServicePoint::query()->where('is_active', true)->orderBy('id')->limit(30)->get(['id', 'name', 'city', 'address'])->toArray(),
            'note' => $items === []
                ? 'Ничего не найдено по этой модели. Не подставляй стёкла других машин. Предложи заявку на подбор через prepare_order с reference.'
                : 'Варианты каталога без известных противоречий запросу. Это не гарантия совместимости: при fitment.status=review_required нужна проверка. Сначала попроси выбрать конкретную карточку; не запрашивай контакты до выбора.',
        ];
    }
}
