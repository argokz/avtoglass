<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Ordering\DraftService;
use App\Models\Assignment;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsQuoteFixtures;
use Tests\Support\BuildsVerifiedQuoteFixture;
use Tests\TestCase;

class FulfillmentQuoteTest extends TestCase
{
    use BuildsQuoteFixtures, BuildsVerifiedQuoteFixture, RefreshDatabase;

    private function choose(array $f, array $fulfillment): array
    {
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['fulfillment' => $fulfillment]);

        return $f;
    }

    private function installation(): array
    {
        $f = $this->verifiedFixture();
        $f['service'] = Service::factory()->create(['glass_type' => 'windshield', 'price' => '5000.25', 'duration_minutes' => 60, 'is_default' => true]);

        return $this->choose($f, ['type' => 'installation', 'slot_start' => '2026-09-21T10:00:00+05:00', 'slot_end' => '2026-09-21T11:30:00+05:00']);
    }

    public function test_pickup_requires_explicit_offer_point_permission_and_matching_city(): void
    {
        $f = $this->fixture();
        DB::table('offer_fulfillment_points')->delete();
        $this->assertContains('DELIVERY_REVIEW_REQUIRED', $this->prepare($f)['reasons']);
        $f = $this->choose($f, ['city' => 'Астана']);
        $this->assertSame('review_required', $this->prepare($f)['readiness']);
        $f = $this->choose($f, ['service_point_id' => null]);
        $this->assertSame('incomplete', $this->prepare($f)['readiness']);
    }

    public function test_delivery_tariff_is_unknown_until_configured_and_zero_is_a_valid_explicit_fee(): void
    {
        $f = $this->choose($this->fixture(), ['type' => 'delivery', 'address' => 'Тестовая, 12']);
        $quote = $this->prepare($f);
        $this->assertNull($quote['delivery_total']);
        $this->assertNull($quote['total']);
        $this->assertSame(10005, $quote['known_total']['amount_minor']);
        $this->assertContains('DELIVERY_REVIEW_REQUIRED', $quote['reasons']);
        config(['ordering.fulfillment.delivery_zones.караганда' => ['fee_minor' => 150025, 'supplier_ids' => [$f['offer']->supplier_id]]]);
        $quote = $this->prepare($f);
        $this->assertSame('ready', $quote['readiness']);
        $this->assertSame(160030, $quote['total']['amount_minor']);
        config(['ordering.fulfillment.delivery_zones.караганда.fee_minor' => 0]);
        $this->assertSame(10005, $this->prepare($f)['total']['amount_minor']);
        $f = $this->choose($f, ['address' => null]);
        $this->assertSame('incomplete', $this->prepare($f)['readiness']);
    }

    public function test_installation_adds_work_once_and_checks_the_single_slot(): void
    {
        $f = $this->installation();
        $quote = $this->prepare($f);
        $this->assertSame('ready', $quote['readiness']);
        $this->assertSame(3335, $quote['goods_total']['amount_minor']);
        $this->assertSame(500025, $quote['services_total']['amount_minor']);
        $this->assertSame(503360, $quote['total']['amount_minor']);
        $this->assertCount(1, $quote['service_items']);
        $this->assertSame(90, $quote['fulfillment']['duration_minutes']);
        $this->assertDatabaseCount('assignments', 0);
        $f = $this->choose($f, ['slot_end' => '2026-09-21T11:00:00+05:00']);
        $this->assertContains('SLOT_DURATION_CHANGED', $this->prepare($f)['reasons']);
    }

    public function test_missing_service_is_not_free_installation(): void
    {
        $f = $this->installation();
        $f['service']->delete();
        $quote = $this->prepare($f);
        $this->assertNull($quote['services_total']);
        $this->assertNull($quote['total']);
        $this->assertSame(3335, $quote['known_total']['amount_minor']);
        $this->assertContains('SERVICE_REVIEW_REQUIRED', $quote['reasons']);
    }

    public function test_explicit_zero_service_price_is_known_and_not_added_twice(): void
    {
        $f = $this->installation();
        $f['service']->update(['price' => '0.00']);
        $quote = $this->prepare($f);
        $this->assertSame('ready', $quote['readiness']);
        $this->assertSame(0, $quote['services_total']['amount_minor']);
        $this->assertSame(3335, $quote['total']['amount_minor']);
    }

    public function test_specific_glass_service_precedes_cheaper_generic_default(): void
    {
        $f = $this->installation();
        Service::factory()->create(['glass_type' => null, 'price' => '1.00', 'is_default' => true, 'sort' => -100]);
        $this->assertSame($f['service']->id, $this->prepare($f)['service_items'][0]['service_id']);
    }

    public function test_exact_article_does_not_bypass_vehicle_fitment_for_installation(): void
    {
        $f = $this->installation();
        $f['item']['selection_mode'] = 'exact_article';
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['items' => [$f['item']]]);
        $quote = $this->prepare($f);
        $this->assertSame('review_required', $quote['readiness']);
        $this->assertContains('FITMENT_REVIEW_REQUIRED', $quote['reasons']);
    }

    public function test_slot_capacity_uses_peak_overlap_and_end_touch_does_not_block_next_slot(): void
    {
        $f = $this->installation();
        $f['point']->update(['bays' => 2]);
        foreach ([['10:00', 45], ['10:45', 45]] as [$time, $duration]) {
            Assignment::create(['order_id' => Order::factory()->create()->id, 'type' => 'installation', 'status' => 'planned',
                'service_point_id' => $f['point']->id, 'scheduled_at' => '2026-09-21 '.$time.':00', 'duration_minutes' => $duration]);
        }
        $this->assertSame('ready', $this->prepare($f)['readiness']);
        $f['point']->update(['bays' => 1]);
        $this->assertContains('SLOT_UNAVAILABLE', $this->prepare($f)['reasons']);
        $f = $this->choose($f, ['slot_start' => '2026-09-21T11:30:00+05:00', 'slot_end' => '2026-09-21T13:00:00+05:00']);
        $this->assertSame('ready', $this->prepare($f)['readiness']);
    }

    public function test_visit_has_one_travel_fee_and_requires_a_verified_visit_resource(): void
    {
        $f = $this->installation();
        Service::factory()->create(['slug' => 'vyezd-mastera', 'glass_type' => null, 'price' => '2000.10', 'is_default' => false]);
        $f = $this->choose($f, ['type' => 'visit', 'address' => 'Тестовая, 12']);
        $quote = $this->prepare($f);
        $this->assertSame(500025, $quote['services_total']['amount_minor']);
        $this->assertSame(200010, $quote['delivery_total']['amount_minor']);
        $this->assertSame(703370, $quote['total']['amount_minor']);
        $this->assertSame('review_required', $quote['readiness']);
        $this->assertContains('VISIT_RESOURCE_REVIEW_REQUIRED', $quote['reasons']);
    }
}
