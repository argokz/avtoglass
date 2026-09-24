<?php

namespace Tests\Feature;

use App\Catalog\ModelNameAudit;
use App\Filament\Resources\CarModels\CarModelResource;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Разбор прайса иногда оставляет в имени модели хвост атрибута, и покупатель
 * видит его в подборе: «4RUNNER а», «Land Cruiser Оригин». Такое имя нельзя
 * ни удалить (за моделью висят товары), ни переписать кодом — верное название
 * знает человек. Значит, задача аудита — заметить и позвать.
 */
class ModelNameAuditTest extends TestCase
{
    use RefreshDatabase;

    private function audit(): ModelNameAudit
    {
        return new ModelNameAudit;
    }

    #[DataProvider('мусор')]
    public function test_обрывок_в_названии_находится(string $name, ?string $brand): void
    {
        $this->assertNotNull($this->audit()->reason($name, $brand));
    }

    /** @return array<string, array{string, ?string}> */
    public static function мусор(): array
    {
        return [
            'буква-хвост' => ['4RUNNER а', 'Toyota'],
            'сокращение через точку' => ['2110 п.ш', 'ВАЗ'],
            'обрывок слова' => ['Land Cruiser Оригин', 'Toyota'],
            'смешанная раскладка' => ['СLK-Class', 'Mercedes-Benz'],
            'пустое имя' => ['', 'Toyota'],
        ];
    }

    #[DataProvider('живые')]
    public function test_живое_название_не_трогаем(string $name, ?string $brand): void
    {
        $this->assertNull(
            $this->audit()->reason($name, $brand),
            "«{$name}» помечено зря: ".(string) $this->audit()->reason($name, $brand)
        );
    }

    /**
     * Ложное срабатывание здесь дороже пропуска: менеджер, разгребающий
     * очередь из настоящих названий, перестаёт ей верить.
     *
     * @return array<string, array{string, ?string}>
     */
    public static function живые(): array
    {
        return [
            'латиница' => ['Camry', 'Toyota'],
            'русская марка' => ['1111 Ока', 'ВАЗ'],
            'русское слово в имени' => ['2121 новый', 'ВАЗ'],
            'русский тип кузова' => ['3163 Пикап', 'УАЗ'],
            'серия по-русски' => ['6/R серия', 'Scania'],
            'страна сборки' => ['Capella /626 Китай', 'Mazda'],
            'без марки' => ['Land Cruiser', null],
        ];
    }

    public function test_команда_помечает_и_называет_причину(): void
    {
        $brand = CarBrand::factory()->create(['name' => 'Toyota']);
        $плохая = CarModel::factory()->for($brand, 'brand')->create(['name' => '4RUNNER а']);
        $хорошая = CarModel::factory()->for($brand, 'brand')->create(['name' => 'Camry']);

        $this->artisan('catalog:flag-models --apply')->assertSuccessful();

        $this->assertTrue($плохая->fresh()->needs_review);
        $this->assertNotNull($плохая->fresh()->review_note);
        $this->assertFalse($хорошая->fresh()->needs_review);
    }

    /** Без --apply команда только показывает: пометки в базу не идут. */
    public function test_без_apply_ничего_не_меняется(): void
    {
        $model = CarModel::factory()->create(['name' => '4RUNNER а']);

        $this->artisan('catalog:flag-models')->assertSuccessful();

        $this->assertFalse($model->fresh()->needs_review);
    }

    /**
     * Исправленное имя снимает пометку само: иначе очередь не пустеет
     * и в неё перестают заходить.
     */
    public function test_исправленное_название_снимает_пометку(): void
    {
        $model = CarModel::factory()->create(['name' => '4RUNNER а']);
        $this->artisan('catalog:flag-models --apply');
        $this->assertTrue($model->fresh()->needs_review);

        $model->update(['name' => '4Runner']);
        $this->artisan('catalog:flag-models --apply');

        $this->assertFalse($model->fresh()->needs_review);
    }

    public function test_счётчик_в_меню_показывает_очередь(): void
    {
        CarModel::factory()->count(2)->create(['needs_review' => true]);
        CarModel::factory()->create(['needs_review' => false]);

        $this->assertSame('2', CarModelResource::getNavigationBadge());
    }

    public function test_пустая_очередь_счётчик_не_показывает(): void
    {
        CarModel::factory()->create(['needs_review' => false]);

        $this->assertNull(CarModelResource::getNavigationBadge());
    }

    public function test_список_моделей_открывается(): void
    {
        CarModel::factory()->create(['needs_review' => true, 'review_note' => 'хвост «а» похож на обрывок']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(CarModelResource::getUrl('index'))
            ->assertOk();
    }

    public function test_карточка_модели_открывается_на_правку(): void
    {
        $model = CarModel::factory()->create(['needs_review' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(CarModelResource::getUrl('edit', ['record' => $model]))
            ->assertOk();
    }
}
