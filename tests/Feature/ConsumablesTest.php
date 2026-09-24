<?php

namespace Tests\Feature;

use App\Console\Commands\BuildCatalog;
use App\Enums\GlassType;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Раздел расходников.
 *
 * Молдинг, клей и полимер универсальны: марки у них нет, и в дереве
 * «марка → модель → стекло» им места не нашлось — семь десятков ходовых
 * позиций лежали в прайсе и не доезжали до витрины вовсе.
 */
class ConsumablesTest extends TestCase
{
    use RefreshDatabase;

    private function расходник(string $name, float $price = 270, bool $published = true): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'glass_type' => GlassType::Consumable,
            'price_min' => $price,
            'price_max' => $price,
            'is_published' => $published,
        ]);
    }

    public function test_раздел_открывается_и_показывает_расходники(): void
    {
        $this->расходник('Молдинг Транзит Т1');

        $this->get(route('catalog.consumables'))
            ->assertOk()
            ->assertSee('Молдинг Транзит Т1')
            ->assertSee('270 ₸');
    }

    /** Неопубликованное на витрину не попадает — как и везде. */
    public function test_неопубликованный_расходник_не_показывается(): void
    {
        $this->расходник('Молдинг чёрный', published: false);

        $this->get(route('catalog.consumables'))
            ->assertOk()
            ->assertDontSee('Молдинг чёрный');
    }

    /**
     * Расходник, привязанный к машине, живёт в каталоге этой машины —
     * в универсальном разделе ему делать нечего, иначе один и тот же товар
     * покупатель встретит дважды.
     */
    public function test_расходник_с_привязкой_к_машине_в_разделе_не_показывается(): void
    {
        $привязанный = $this->расходник('Молдинг для Camry');
        $brand = CarBrand::factory()->create();
        $model = CarModel::factory()->for($brand, 'brand')->create();
        Fitment::factory()->create([
            'product_id' => $привязанный->id,
            'car_model_id' => $model->id,
        ]);

        $универсальный = $this->расходник('Молдинг универсальный');

        $this->get(route('catalog.consumables'))
            ->assertOk()
            ->assertSee($универсальный->name)
            ->assertDontSee($привязанный->name);
    }

    /** Стёкла в разделе расходников не показываем. */
    public function test_стекло_в_раздел_не_попадает(): void
    {
        Product::factory()->create([
            'name' => 'Лобовое стекло Toyota Camry',
            'glass_type' => GlassType::Windshield,
            'price_min' => 30000,
            'is_published' => true,
        ]);

        $this->get(route('catalog.consumables'))
            ->assertOk()
            ->assertDontSee('Лобовое стекло Toyota Camry');
    }

    public function test_поиск_по_названию_работает(): void
    {
        $this->расходник('Полимер для сколов', 22000);
        $this->расходник('Молдинг Лепесток Л1');

        $this->get(route('catalog.consumables', ['q' => 'полимер']))
            ->assertOk()
            ->assertSee('Полимер для сколов')
            ->assertDontSee('Молдинг Лепесток Л1');
    }

    public function test_пустой_поиск_объясняет_что_делать(): void
    {
        $this->расходник('Молдинг Лепесток Л1');

        $this->get(route('catalog.consumables', ['q' => 'такого-нет']))
            ->assertOk()
            ->assertSee('ничего не нашли')
            ->assertSee(settings()->phones()[0]['display']);
    }

    /** Счётчик склоняется: «3 позиции», а не «3 позиций». */
    public function test_счётчик_позиций_склоняется(): void
    {
        $this->расходник('Молдинг А');
        $this->расходник('Молдинг Б');
        $this->расходник('Молдинг В');

        $this->get(route('catalog.consumables'))
            ->assertOk()
            ->assertSee('3 позиции');
    }

    /** С главной плитка «Молдинги и клей» ведёт сюда, а не в подбор по маркам. */
    public function test_с_главной_есть_ссылка_на_раздел(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('catalog.consumables'), false);
    }

    /**
     * Имя расходника берётся из прайса и чистится.
     *
     * В выгрузке они идут с внутренним номером и прямыми кавычками, причём
     * непарными: «001-Молдинг "Сиерра 2 (с пазом)». Номер покупателю не нужен,
     * а одинокая ёлочка выглядит как оборванное название.
     */
    #[DataProvider('имена')]
    public function test_имя_расходника_чистится(string $изПрайса, string $ожидается): void
    {
        $метод = new \ReflectionMethod(BuildCatalog::class, 'consumableName');
        $метод->setAccessible(true);

        $this->assertSame($ожидается, $метод->invoke(app(BuildCatalog::class), $изПрайса));
    }

    /** @return array<string, array{string, string}> */
    public static function имена(): array
    {
        return [
            'номер убирается' => ['001-Молдинг Универсальный У1', 'Молдинг Универсальный У1'],
            'номер с пробелом' => ['002- Молдинг Транзит Т1', 'Молдинг Транзит Т1'],
            'парные кавычки' => ['001-Молдинг "Универсальный" У1', 'Молдинг «Универсальный» У1'],
            'непарная кавычка убирается' => ['012- Молдинг "Транзит2 (с пазом) 20 мм', 'Молдинг Транзит2 (с пазом) 20 мм'],
            'без номера не трогаем' => ['Полимер для сколов', 'Полимер для сколов'],
            'лишние пробелы' => ['P-082   полимер  для сколов', 'P-082 полимер для сколов'],
        ];
    }
}
