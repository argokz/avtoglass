<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\AssistantSearch;
use PHPUnit\Framework\TestCase;

class AssistantSearchTest extends TestCase
{
    public function test_explicit_option_denials_and_uncertainty_are_not_positive_facts(): void
    {
        $this->assertSame(['camera' => false], AssistantSearch::options('Ошибся, камеры нет'));
        $this->assertSame(['camera' => false], AssistantSearch::options('Камерасы жоқ'));
        $this->assertSame(['camera' => false], AssistantSearch::options('Қателестім, камера жоқ'));
        $this->assertSame(['camera' => null], AssistantSearch::options('Камеру не знаю'));
        $this->assertSame(['rain_sensor' => false], AssistantSearch::options('Датчика дождя нет'));
        $this->assertSame(['rain_sensor' => false], AssistantSearch::options('Жаңбыр датчигі жоқ'));
    }

    public function test_old_catalogue_snapshots_exclude_wrong_year_ranges(): void
    {
        $items = array_map(fn ($name) => ['name' => $name], [
            'Toyota RAV4 1994–2000', 'Toyota RAV4 2000–2006', 'Toyota RAV4 2006–н.в.',
        ]);
        $this->assertSame([$items[0]], AssistantSearch::keepRelevant($items, 'RAV4 1995'));
    }

    public function test_full_references_are_preserved_and_brand_is_not_mistaken_for_model(): void
    {
        $this->assertSame('LEM-TIG-VO0099', AssistantSearch::parameters(['query' => 'артикул LEM-TIG-VO0099'])['query']);
        $this->assertSame('8372AGNHMVW1B', AssistantSearch::parameters(['query' => 'еврокод 8372AGNHMVW1B'])['query']);
        $this->assertSame('camry', AssistantSearch::parameters(['query' => 'Toyota Camry 2018 лобовое пожалуйста'])['query']);
        $this->assertSame('JOURNEY 001', AssistantSearch::parameters(['query' => 'Найди JOURNEY 001'])['query']);
        $this->assertNull(AssistantSearch::spacedReference('Camry 2018'));
        $this->assertNull(AssistantSearch::spacedReference('Toyota 200'));
        $this->assertNull(AssistantSearch::spacedReference('Оформи offer_id 999999'));
    }

    public function test_single_year_revision_is_not_an_unbounded_fitment(): void
    {
        $items = array_map(fn ($name) => ['name' => $name, 'vehicle_ranges' => [
            ['car_model_id' => 16, 'year_from' => null, 'year_to' => null],
        ]], [
            'Лобовое стекло Toyota RAV4 1994–2000 (ЗП)',
            'Лобовое стекло Toyota RAV4 изм. 2015 (ДД, полный обогрев)',
            'Лобовое стекло Toyota RAV4 2019 (ДД, полный обогрев)',
            'Лобовое стекло Toyota RAV4 а 2019 (ДД, камера, полный обогрев)',
        ]);
        $this->assertSame([$items[0]], AssistantSearch::keepRelevant($items, 'Лобовое стекло RAV4 1995 года без установки доставка Караганда, куйбышева 15'));
        $this->assertSame([$items[1]], AssistantSearch::keepRelevant($items, 'RAV4 2015'));
        $this->assertSame([], AssistantSearch::keepRelevant(array_slice($items, 1), 'RAV4 2020'));
    }

    public function test_kazakh_glass_types_year_corrections_options_and_multi_reference_lists_are_extracted(): void
    {
        $kkParams = AssistantSearch::parameters(['query' => 'Тойота Камри 2018 алдыңғы әйнек']);
        $this->assertSame('камри', $kkParams['query']);
        $this->assertSame(2018, $kkParams['year']);
        $this->assertSame('windshield', $kkParams['glass_type']);

        $corrected = AssistantSearch::parameters(['query' => 'Раньше написал Camry 2018, исправляю: Camry 1995 лобовое']);
        $this->assertSame('camry', $corrected['query']);
        $this->assertSame(1995, $corrected['year']);
        $this->assertSame('windshield', $corrected['glass_type']);

        $unknownRef = AssistantSearch::parameters(['query' => 'AUDIT-UNKNOWN артикулын тап']);
        $this->assertSame('AUDIT-UNKNOWN', $unknownRef['query']);

        $cameraParams = AssistantSearch::parameters(['query' => 'Camry 2018 ADAS камерасы бар алдыңғы әйнек']);
        $this->assertSame(['camera' => true], $cameraParams['options']);

        $noCameraParams = AssistantSearch::parameters(['query' => 'Лобовое Camry 2018 без камеры']);
        $this->assertSame(['camera' => false], $noCameraParams['options']);

        $this->assertSame(['DEMO-1', 'DEMO-2'], AssistantSearch::references('Оптовая заявка: DEMO-1 3 штуки, DEMO-2 2 штуки'));
        $this->assertSame(['DEMO-1' => 3, 'DEMO-2' => 2], AssistantSearch::lineQuantities('Көтерме өтінім: DEMO-1 3 дана, DEMO-2 2 дана'));

        $offers = [
            ['offer_id' => 1, 'article' => 'DEMO-1', 'name' => 'Стекло 1'],
            ['offer_id' => 2, 'article' => 'DEMO-2', 'name' => 'Стекло 2'],
            ['offer_id' => 3, 'article' => 'OTHER-9', 'name' => 'Стекло 3'],
        ];
        $retained = AssistantSearch::keepRelevant($offers, 'Оптовая заявка: DEMO-1 3 штуки, DEMO-2 2 штуки');
        $this->assertSame([1, 2], array_column($retained, 'offer_id'));
    }
}
