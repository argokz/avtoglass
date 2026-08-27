<?php

namespace Tests\Feature;

use App\Models\CarBrand;
use App\Models\CarModelAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarModelAliasTest extends TestCase
{
    use RefreshDatabase;

    /** Псевдоним из базы должен доезжать до нормализатора, иначе он бесполезен. */
    public function test_псевдоним_из_базы_применяется_при_разборе(): void
    {
        $brand = CarBrand::factory()->create(['name' => 'Mercedes-Benz']);

        CarModelAlias::create([
            'car_brand_id' => $brand->id,
            'alias' => 'W140, S-Class',
            'model_name' => 'S-Class',
            'generation' => 'W140',
        ]);

        $result = CarModelAlias::normalizer()->normalize('Mercedes-Benz', 'W140, S-Class');

        $this->assertSame('S-Class', $result->name);
        $this->assertSame('W140', $result->generation);
    }

    /** Псевдоним действует только внутри своей марки. */
    public function test_псевдоним_не_протекает_в_чужую_марку(): void
    {
        $bmw = CarBrand::factory()->create(['name' => 'BMW']);

        CarModelAlias::create([
            'car_brand_id' => $bmw->id,
            'alias' => 'X5 RED',
            'model_name' => 'X5',
        ]);

        $result = CarModelAlias::normalizer()->normalize('Audi', 'X5 RED');

        // для Audi псевдонима нет — сработают обычные правила чистки
        $this->assertFalse($result->fromAlias);
    }

    public function test_без_псевдонимов_нормализатор_работает_на_правилах(): void
    {
        $result = CarModelAlias::normalizer()->normalize('Volkswagen', 'Golf II');

        $this->assertSame('Golf', $result->name);
        $this->assertSame('II', $result->generation);
    }
}
