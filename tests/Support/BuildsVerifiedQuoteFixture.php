<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ordering\DraftService;
use App\Models\CarModel;
use App\Models\Fitment;

trait BuildsVerifiedQuoteFixture
{
    private function verifiedFixture(): array
    {
        $f = $this->fixture();
        $f['model'] = CarModel::factory()->create();
        $f['fitment'] = Fitment::factory()->create(['product_id' => $f['offer']->product_id, 'car_model_id' => $f['model']->id,
            'year_from' => 2017, 'year_to' => 2024, 'body_type' => 'sedan', 'doors' => '4', 'is_verified' => true]);
        $f['vehicle'] = ['model_id' => $f['model']->id, 'brand_id' => $f['model']->car_brand_id, 'year' => 2020, 'body' => 'sedan', 'doors' => '4',
            'options' => array_fill_keys(config('ordering.fitment.required_options.windshield'), false)];
        $f['item']['quantity'] = 1;
        $f['item']['selection_mode'] = 'vehicle_fitment';
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version, [
            'vehicle' => $f['vehicle'], 'items' => [$f['item']],
        ]);

        return $f;
    }
}
