<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Ordering\DraftService;
use App\Models\CarGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsQuoteFixtures;
use Tests\Support\BuildsVerifiedQuoteFixture;
use Tests\TestCase;

class FitmentQuoteTest extends TestCase
{
    use BuildsQuoteFixtures, BuildsVerifiedQuoteFixture, RefreshDatabase;

    public function test_verified_model_year_body_and_explicit_options_allow_vehicle_fitment(): void
    {
        $f = $this->verifiedFixture();
        $quote = $this->prepare($f);
        $this->assertSame('ready', $quote['readiness']);
        $this->assertSame('matched', $quote['items'][0]['fitment']['status']);
        $this->assertSame($f['fitment']->id, $quote['items'][0]['fitment']['fitment_id']);
    }

    #[DataProvider('uncertainCases')]
    public function test_uncertain_or_conflicting_vehicle_facts_require_review(string $change): void
    {
        $f = $this->verifiedFixture();
        $vehicle = $f['vehicle'];
        match ($change) {
            'unknown-camera' => $vehicle['options']['camera'] = null,
            'wrong-camera' => $vehicle['options']['camera'] = true,
            'unknown-year' => $vehicle['year'] = null,
            'wrong-year' => $vehicle['year'] = 2016,
            'unknown-body' => $vehicle['body'] = null,
            'wrong-body' => $vehicle['body'] = 'wagon',
            'wrong-doors' => $vehicle['doors'] = '2',
            'wrong-brand' => $vehicle['brand_id'] = $f['model']->car_brand_id + 1000,
            'unverified' => $f['fitment']->update(['is_verified' => false]),
            'unknown-years' => $f['fitment']->update(['year_from' => null]),
            'note' => $f['fitment']->update(['note' => 'Only for one trim']),
            'model-review' => $f['model']->update(['needs_review' => true]),
        };
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['vehicle' => $vehicle]);
        $quote = $this->prepare($f);
        $this->assertSame('review_required', $quote['readiness']);
        $this->assertContains('FITMENT_REVIEW_REQUIRED', $quote['reasons']);
        if ($change === 'unknown-camera') {
            $this->assertSame(['camera'], $quote['items'][0]['fitment']['missing_options']);
        }
    }

    public static function uncertainCases(): array
    {
        return array_map(fn ($change) => [$change], ['unknown-camera', 'wrong-camera', 'unknown-year', 'wrong-year', 'unknown-body', 'wrong-body', 'wrong-doors', 'wrong-brand', 'unverified', 'unknown-years', 'note', 'model-review']);
    }

    public function test_generation_must_be_explicit_and_belong_to_the_verified_model(): void
    {
        $f = $this->verifiedFixture();
        $generation = CarGeneration::create(['car_model_id' => $f['model']->id, 'code' => 'XV70', 'year_from' => 2017, 'year_to' => 2024]);
        $f['fitment']->update(['car_generation_id' => $generation->id]);
        $this->assertContains('FITMENT_REVIEW_REQUIRED', $this->prepare($f)['reasons']);
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['vehicle' => ['generation_id' => $generation->id]]);
        $this->assertSame('ready', $this->prepare($f)['readiness']);
        $generation->update(['year_to' => 2019]);
        $this->assertContains('FITMENT_REVIEW_REQUIRED', $this->prepare($f)['reasons']);
    }

    public function test_exact_article_requires_confirmation_and_does_not_claim_vehicle_compatibility(): void
    {
        $f = $this->fixture();
        $this->assertSame('exact_article', $this->prepare($f)['items'][0]['fitment']['status']);
        $f['item']['manufacturer_confirmed'] = false;
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['items' => [$f['item']]]);
        $this->assertContains('FITMENT_REVIEW_REQUIRED', $this->prepare($f)['reasons']);
    }

    public function test_changed_article_cannot_reuse_confirmation_of_the_previous_article(): void
    {
        $f = $this->fixture();
        $f['offer']->update(['article' => 'REPLACEMENT']);
        $quote = $this->prepare($f);
        $this->assertContains('FITMENT_REVIEW_REQUIRED', $quote['reasons']);
        $this->assertSame('ARTICLE_CONFIRMATION_REQUIRED', $quote['items'][0]['fitment']['detail']);
    }
}
