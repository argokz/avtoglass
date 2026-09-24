<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Pricing\QuoteDraft;
use App\Models\Offer;
use App\Models\Order;
use App\Models\ServicePoint;
use App\Models\StockReservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SubmitDraftConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $at = CarbonImmutable::parse('2026-09-20T05:00:00+00:00');
        $this->travelTo($at);
        CarbonImmutable::setTestNow($at);
        config(['ordering.auto_checkout_enabled' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_last_unit_allows_one_success_and_keeps_losing_draft_active(): void
    {
        $offer = Offer::factory()->create([
            'article' => 'LAST-1',
            'price_retail' => '33.35',
            'price_wholesale' => '20.00',
            'stock_qty' => 1,
            'auto_checkout_enabled' => true,
            'stock_verified_at' => now(),
            'price_valid_until' => now()->addHours(2),
        ]);
        $point = ServicePoint::factory()->create(['city' => 'Караганда']);
        DB::table('offer_fulfillment_points')->insert([
            'offer_id' => $offer->id,
            'service_point_id' => $point->id,
            'pickup_enabled' => true,
            'installation_enabled' => true,
        ]);

        $cases = [];
        foreach (['A', 'B'] as $label) {
            $token = bin2hex(random_bytes(32));
            $buyer = BuyerContext::guest($token);
            $draft = app(DraftService::class)->create($buyer);
            $draft = app(DraftService::class)->update($buyer, $draft->id, 1, [
                'items' => [[
                    'line_id' => (string) Str::uuid(),
                    'offer_id' => $offer->id,
                    'quantity' => 1,
                    'selection_mode' => 'exact_article',
                    'article_confirmed' => true,
                    'manufacturer_confirmed' => true,
                ]],
                'contacts' => ['name' => 'Данил '.$label, 'phone' => '+7 700 123-45-6'.($label === 'A' ? '0' : '1')],
                'fulfillment' => ['type' => 'pickup', 'city' => 'Караганда', 'service_point_id' => $point->id],
            ]);
            $quote = app(QuoteDraft::class)->prepare($buyer, $draft->id, $draft->version)->jsonSerialize();
            $this->assertSame('ready', $quote['readiness']);
            $cases[$label] = compact('token', 'draft', 'quote');
        }

        $prefix = 'submit-race-'.substr((string) Str::uuid(), 0, 12);
        $processes = [];
        foreach ($cases as $label => $case) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Support/submit-reserve-worker.php')], base_path());
            $process->setInput(json_encode([
                'database' => DB::connection()->getConfig(),
                'key' => config('app.key'),
                'token' => $case['token'],
                'application_name' => $prefix.'-'.$label,
                'draft_id' => $case['draft']->id,
                'quote_id' => $case['quote']['id'],
                'expected_version' => $case['draft']->version,
                'command_key' => 'confirm-'.$label,
            ], JSON_THROW_ON_ERROR));
            $process->setTimeout(60);
            $processes[$label] = $process;
        }

        foreach ($processes as $process) {
            $process->start();
        }

        $results = [];
        foreach ($processes as $label => $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $results[$label] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        $wins = collect($results)->filter(fn ($row) => ($row['ok'] ?? false) === true);
        $losses = collect($results)->filter(fn ($row) => ($row['ok'] ?? false) === false);
        $this->assertSame(1, $wins->count(), json_encode($results));
        $this->assertSame(1, $losses->count());
        $this->assertContains($losses->first()['error'], ['INSUFFICIENT_STOCK', 'QUOTE_CHANGED']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame(1, (int) StockReservation::query()->sum('quantity'));

        $winnerLabel = $wins->keys()->first();
        $loserLabel = $losses->keys()->first();
        $this->assertSame('submitted', OrderDraft::query()->findOrFail($cases[$winnerLabel]['draft']->id)->status);
        $this->assertSame('active', OrderDraft::query()->findOrFail($cases[$loserLabel]['draft']->id)->status);
        $this->assertSame(Order::query()->sole()->id, $wins->first()['order_id']);
    }
}
