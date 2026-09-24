<?php

namespace App\Ai;

use App\Actions\CreateOrder;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\SessionBuyer;
use App\Enums\FulfillmentType;
use App\Enums\GlassType;
use App\Enums\OrderSource;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Инструменты AI-помощника: то, что Gemini может «позвать» вместо ответа.
 *
 * Цены и наличие модель не выдумывает — только из search_products. Заявку
 * создаёт тот же CreateOrder, что и формы на сайте: задача менеджеру и
 * оповещение смены работают как обычно.
 */
class AssistantTools
{
    public function __construct(private readonly SearchOffers $search) {}

    /**
     * Декларации для Gemini function calling.
     *
     * @return list<array{name:string,description:string,parameters:array}>
     */
    public static function declarations(): array
    {
        return [
            [
                'name' => 'search_products',
                'description' => 'Ищет автостёкла в каталоге по названию, еврокоду, OEM или артикулу. Возвращает цену, наличие и ссылку. Вызывай всегда, когда нужна цена или наличие — не придумывай.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Строка поиска: модель авто, еврокод, OEM, артикул'],
                        'glass_type' => [
                            'type' => 'string',
                            'enum' => array_column(GlassType::cases(), 'value'),
                            'description' => 'Тип стекла, если понятен из диалога',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'create_order',
                'description' => 'Оформляет заявку. Вызывать только когда клиент явно попросил оформить, назвал имя и телефон и подтвердил согласие на обработку персональных данных.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Имя клиента'],
                        'phone' => ['type' => 'string', 'description' => 'Телефон в любом формате'],
                        'product_id' => ['type' => 'integer', 'description' => 'ID стекла из search_products, если клиент выбрал конкретное'],
                        'fulfillment' => [
                            'type' => 'string',
                            'enum' => array_column(FulfillmentType::cases(), 'value'),
                            'description' => 'installation — замена в сервисе, visit — выезд, delivery — доставка, pickup — самовывоз',
                        ],
                        'address' => ['type' => 'string', 'description' => 'Адрес, обязателен для доставки и выезда'],
                        'comment' => ['type' => 'string'],
                        'privacy_confirmed' => ['type' => 'boolean', 'description' => 'Клиент согласился на обработку персональных данных'],
                    ],
                    'required' => ['name', 'phone', 'privacy_confirmed'],
                ],
            ],
        ];
    }

    public function call(string $name, array $args): array
    {
        return match ($name) {
            'search_products' => $this->searchProducts($args),
            'create_order' => $this->createOrder($args),
            default => ['error' => "Неизвестный инструмент {$name}"],
        };
    }

    public function searchProducts(array $args): array
    {
        $term = trim((string) ($args['query'] ?? ''));

        if ($term === '') {
            return ['items' => [], 'note' => 'Пустой запрос — уточните у клиента модель авто или еврокод.'];
        }

        $type = GlassType::tryFrom((string) ($args['glass_type'] ?? ''));
        $result = $this->search->search(SessionBuyer::current(), array_filter([
            'q' => $term,
            'page' => 1,
            'per_page' => 8,
            'glass_type' => $type?->value,
        ], fn ($value) => $value !== null));

        $items = [];
        $seen = [];
        foreach ($result['items'] as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $product = Product::query()->find($productId);
            if ($product === null) {
                continue;
            }
            $glass = GlassType::tryFrom((string) ($row['glass_type'] ?? ''));
            $priceMinor = $row['unit_price']['amount_minor'] ?? null;
            $items[] = [
                'id' => $productId,
                'name' => $row['name'] ?? $product->name,
                'eurocode' => $row['eurocode'] ?? $product->eurocode,
                'type' => $glass?->label() ?? $product->glass_type?->label(),
                'price_kzt' => is_int($priceMinor) ? (int) round($priceMinor / 100) : null,
                'stock' => ((int) ($row['available_qty'] ?? 0)) > 0 ? 'в наличии' : 'под заказ',
                'url' => route('catalog.product', $product),
            ];
            if (count($items) >= 8) {
                break;
            }
        }

        return [
            'items' => $items,
            'note' => $items === [] ? 'Ничего не нашлось — предложите оставить заявку на подбор или позвонить.' : null,
        ];
    }

    public function createOrder(array $args): array
    {
        $phone = Customer::normalizePhone((string) ($args['phone'] ?? ''));

        if ($phone === null || mb_strlen($phone) < 10) {
            return ['error' => 'Телефон не похож на настоящий — уточните номер у клиента.'];
        }

        $name = trim((string) ($args['name'] ?? ''));

        if (mb_strlen($name) < 2) {
            return ['error' => 'Спросите, как к клиенту обращаться.'];
        }

        if (settings()->enabled('privacy_consent') && ! ($args['privacy_confirmed'] ?? false)) {
            return ['error' => 'Без согласия на обработку персональных данных заявку оформить нельзя — спросите его у клиента.'];
        }

        $product = null;
        if (! empty($args['product_id'])) {
            $product = Product::query()
                ->whereKey((int) $args['product_id'])
                ->where('is_published', true)
                ->first();

            if ($product === null) {
                return ['error' => 'Стекло с таким ID уже не на витрине — поищите снова через search_products.'];
            }
        }

        $fulfillment = FulfillmentType::tryFrom((string) ($args['fulfillment'] ?? ''));
        $address = trim((string) ($args['address'] ?? ''));

        if ($fulfillment?->needsAddress() && $address === '') {
            return ['error' => 'Для доставки и выезда нужен адрес — спросите у клиента.'];
        }

        $order = app(CreateOrder::class)->handle([
            'name' => $name,
            'phone' => $args['phone'],
            'comment' => trim((string) ($args['comment'] ?? '')),
            'fulfillment' => $fulfillment?->value,
            'address' => $address !== '' ? $address : null,
            'form_token' => 'ai-'.Str::uuid(),
            'privacy_consent' => ($args['privacy_confirmed'] ?? false) ? '1' : null,
        ], OrderSource::Assistant, $product);

        return [
            'ok' => true,
            'number' => $order->number,
            'total_kzt' => $order->fresh()->total_amount !== null ? round((float) $order->total_amount) : null,
            'note' => 'Назови клиенту номер заявки и скажи, что менеджер перезвонит подтвердить наличие и срок.',
        ];
    }
}
