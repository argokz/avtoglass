<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\AssistantOrderIntent;
use PHPUnit\Framework\TestCase;

class AssistantOrderIntentTest extends TestCase
{
    public function test_shipping_address_does_not_keep_punctuation_from_removed_installation_clause(): void
    {
        $intent = AssistantOrderIntent::fromMessages([['role' => 'user', 'text' => 'Доставка Караганда, Тестовая 2, без установки']]);
        $this->assertSame('Тестовая 2', $intent['address']);
        $this->assertTrue($intent['without_installation']);
    }

    public function test_selection_title_does_not_overwrite_requested_year_or_shipping(): void
    {
        $intent = AssistantOrderIntent::fromMessages([
            ['role' => 'user', 'text' => 'RAV4 1995, 2 стекла, без установки, доставка в Караганду, Куйбышева 15'],
            ['role' => 'model', 'text' => 'Установка в Алматы'],
            ['role' => 'user', 'text' => 'Беру offer_id 5: RAV4 1994–2000'],
            ['role' => 'user', 'text' => 'Связаться 2026-10-15T10:00:00+05:00'],
        ], ['Караганда']);
        $this->assertSame(['year' => 1995, 'quantity' => 2, 'without_installation' => true, 'fulfillment' => 'delivery', 'city' => 'Караганда', 'address' => 'Куйбышева 15'], $intent);
    }

    public function test_explicit_change_to_pickup_clears_delivery_address_and_removes_model_address(): void
    {
        $intent = AssistantOrderIntent::fromMessages([
            ['role' => 'user', 'text' => 'Доставка Караганда, Куйбышева 15, без установки'],
            ['role' => 'user', 'text' => 'Передумал, самовывоз'],
        ]);
        $args = AssistantOrderIntent::checkoutArguments(['fulfillment' => 'delivery', 'address' => 'старый адрес'], $intent);
        $this->assertSame('pickup', $args['fulfillment']);
        $this->assertArrayNotHasKey('address', $args);
        $this->assertStringContainsString('Без установки', $args['comment']);
    }

    public function test_same_message_year_correction_and_kazakh_contacts_and_wholesale_summary_are_preserved(): void
    {
        $intent = AssistantOrderIntent::fromMessages([
            ['role' => 'user', 'text' => 'Бұрын Camry 2018 дедім, түзетемін: Camry 1995 алдыңғы әйнек'],
            ['role' => 'user', 'text' => 'offer_id 1 таңдаймын. Өтінім дайында: Демо Аудит, +77000000003, Қарағанды өзім алып кетемін, 1 дана.'],
        ], ['Караганда']);
        $this->assertSame(1995, $intent['year']);
        $this->assertSame(1, $intent['offer_id']);
        $this->assertSame('Демо Аудит', $intent['name']);
        $this->assertSame('+77000000003', $intent['phone']);
        $this->assertSame('pickup', $intent['fulfillment']);
        $this->assertSame('Караганда', $intent['city']);

        $wholesaleIntent = AssistantOrderIntent::fromMessages([
            ['role' => 'user', 'text' => 'Көтерме өтінім: DEMO-1 3 дана, DEMO-2 2 дана'],
        ]);
        $summary = AssistantOrderIntent::summary($wholesaleIntent, 'kk');
        $this->assertStringContainsString('DEMO-1 × 3 дана', $summary);
        $this->assertStringContainsString('DEMO-2 × 2 дана', $summary);
    }
}
