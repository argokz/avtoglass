<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Runtime\ToolResultSnapshot;
use Tests\TestCase;

class ToolResultSnapshotTest extends TestCase
{
    public function test_search_keeps_query_year_and_options_without_contacts(): void
    {
        $snapshot = ToolResultSnapshot::make('search_products', [
            'query' => 'Camry',
            'year' => 2018,
            'glass_type' => 'windshield',
            'car_model_id' => 15,
            'options' => ['camera' => false, 'invented' => true],
            'name' => 'Секретное имя',
            'phone' => '77001234567',
            'address' => 'ул. Тестовая 1',
        ], [
            'items' => [[
                'offer_id' => 91,
                'product_id' => 10,
                'name' => 'Лобовое Camry',
                'manufacturer' => ['name' => 'КМК'],
                'unit_price' => ['amount_minor' => 3335, 'currency' => 'KZT'],
                'available_qty' => 2,
                'fitment' => ['status' => 'matched'],
            ]],
            'meta' => ['total' => 1, 'has_more' => false],
            'vehicle' => ['car_model_id' => 15, 'year' => 2018],
        ]);

        $this->assertSame([
            'query' => 'Camry',
            'glass_type' => 'windshield',
            'year' => 2018,
            'car_model_id' => 15,
            'options' => ['camera' => false],
        ], $snapshot['args']);
        $this->assertSame(91, $snapshot['result']['items'][0]['offer_id']);
        $this->assertSame('КМК', $snapshot['result']['items'][0]['manufacturer']);
        $this->assertArrayNotHasKey('name', $snapshot['args']);
        $this->assertArrayNotHasKey('phone', $snapshot['args']);
        $this->assertArrayNotHasKey('address', $snapshot['args']);
    }

    public function test_prepare_order_never_copies_contacts_into_args(): void
    {
        $snapshot = ToolResultSnapshot::make('prepare_order', [
            'name' => 'Секретное имя',
            'phone' => '77001234567',
            'offer_id' => 91,
        ], [
            'ok' => true,
            'checkout' => ['id' => 'card-id', 'quote_id' => 'quote-id', 'status' => 'pending'],
        ]);

        $this->assertSame([], $snapshot['args']);
        $this->assertTrue($snapshot['result']['ok']);
        $this->assertSame('card-id', $snapshot['result']['checkout_id']);
    }
}
