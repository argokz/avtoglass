<?php

namespace Tests\Feature;

use App\Livewire\VinLookup;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Экран подбора по VIN.
 *
 * Обещать он должен ровно то, что VIN содержит: марку, страну и год.
 * Модели в VIN нет, и главная проверка здесь — что мы её не выдумываем,
 * а честно возвращаем покупателя к выбору.
 */
class VinLookupTest extends TestCase
{
    use RefreshDatabase;

    private function toyotaWithCamry(): CarBrand
    {
        $brand = CarBrand::factory()->visible()->create([
            'name' => 'Toyota',
            'slug' => 'toyota',
            'models_count' => 1,
        ]);

        $model = CarModel::factory()->visible()->create([
            'car_brand_id' => $brand->id,
            'name' => 'Camry',
            'slug' => 'camry',
            'products_count' => 1,
        ]);

        Fitment::factory()->create([
            'product_id' => Product::factory(),
            'car_model_id' => $model->id,
        ]);

        return $brand;
    }

    // ------------------------------------------------------------------
    // страница
    // ------------------------------------------------------------------

    public function test_страница_подбора_открывается(): void
    {
        $this->get(route('vin.form'))
            ->assertOk()
            ->assertSee('Подбор по VIN')
            ->assertSee('17 знаков');
    }

    // ------------------------------------------------------------------
    // разбор
    // ------------------------------------------------------------------

    public function test_по_vin_показываются_марка_страна_и_год(): void
    {
        $this->toyotaWithCamry();

        Livewire::test(VinLookup::class)
            ->set('vin', 'JTDBR32E560012345')
            ->assertSee('Toyota')
            ->assertSee('Япония')
            ->assertSee('2006');
    }

    /** Модели той же марки — то, из чего покупателю осталось выбрать. */
    public function test_показываются_модели_найденной_марки(): void
    {
        $this->toyotaWithCamry();

        Livewire::test(VinLookup::class)
            ->set('vin', 'JTDBR32E560012345')
            ->assertSee('Camry');
    }

    /**
     * Самое важное обещание экрана: мы не делаем вид, что узнали модель.
     * Иначе покупателю подберут не то стекло.
     */
    public function test_прямо_сказано_что_модель_из_vin_не_читается(): void
    {
        $this->toyotaWithCamry();

        Livewire::test(VinLookup::class)
            ->set('vin', 'JTDBR32E560012345')
            ->assertSee('Модель по VIN бесплатно не определяется');
    }

    /** Буквенный код года двусмыслен, и молчать об этом нельзя. */
    public function test_неоднозначный_год_показан_с_оговоркой(): void
    {
        Livewire::test(VinLookup::class)
            ->set('vin', 'WBA3B5C50DF123456')
            ->assertSee('2013')
            ->assertSee('1983')
            ->assertSee('повторяется раз в 30 лет');
    }

    // ------------------------------------------------------------------
    // чего мы не знаем
    // ------------------------------------------------------------------

    public function test_незнакомый_завод_не_выдаёт_догадку(): void
    {
        Livewire::test(VinLookup::class)
            ->set('vin', 'ABC3B5C50DF123456')
            ->assertSee('Марку по этому VIN мы не опознали')
            ->assertDontSee('Модели ');
    }

    /** Марка распознана, но стекла на неё в каталоге нет — это разные случаи. */
    public function test_марки_нет_в_каталоге_ведём_на_заявку(): void
    {
        Livewire::test(VinLookup::class)
            ->set('vin', 'JTDBR32E560012345')
            ->assertSee('Toyota')
            ->assertSee('такой марки в каталоге пока нет');
    }

    public function test_плохой_vin_объясняет_причину(): void
    {
        Livewire::test(VinLookup::class)
            ->set('vin', 'ABC')
            ->assertSee('В VIN ровно 17 знаков');
    }

    public function test_пустое_поле_не_ругается(): void
    {
        Livewire::test(VinLookup::class)
            ->assertDontSee('Не похоже на VIN')
            ->assertDontSee('ровно 17 знаков.');
    }

    // ------------------------------------------------------------------
    // передача в заявку
    // ------------------------------------------------------------------

    /** Переписывать 17 знаков второй раз никто не станет. */
    public function test_разобранный_vin_попадает_в_форму_заявки(): void
    {
        Livewire::test(VinLookup::class)->set('vin', 'JTDBR32E560012345');

        $this->assertSame('JTDBR32E560012345', session('vin'));

        $this->get(route('order.form'))
            ->assertOk()
            ->assertSee('VIN JTDBR32E560012345', escape: false);
    }

    /** Полунабранная строка в заявку попасть не должна. */
    public function test_недоразобранный_vin_в_сессию_не_кладётся(): void
    {
        Livewire::test(VinLookup::class)->set('vin', 'JTDBR32E');

        $this->assertNull(session('vin'));
    }

    public function test_очистка_убирает_vin_из_сессии(): void
    {
        Livewire::test(VinLookup::class)
            ->set('vin', 'JTDBR32E560012345')
            ->call('clear')
            ->assertSet('vin', '');

        $this->assertNull(session('vin'));
    }
}
