<?php

namespace Database\Seeders;

use App\Enums\GlassType;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Прайс на работы.
 *
 * Заводит то, что есть у любого стекольщика, с ценами по рынку Караганды.
 * Дальше их правят из панели — цены на работу меняются чаще, чем на стекло,
 * и ходить за этим к программисту не нужно.
 */
class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            [
                'name' => 'Установка лобового стекла',
                'slug' => 'ustanovka-lobovogo',
                'price' => 15000,
                'glass_type' => GlassType::Windshield,
                'duration_minutes' => 120,
                'is_default' => true,
                'sort' => 10,
                'description' => 'Снятие старого стекла, подготовка проёма, вклейка, полимеризация клея.',
            ],
            [
                'name' => 'Установка заднего стекла',
                'slug' => 'ustanovka-zadnego',
                'price' => 12000,
                'glass_type' => GlassType::Rear,
                'duration_minutes' => 90,
                'is_default' => true,
                'sort' => 20,
            ],
            [
                'name' => 'Установка бокового стекла',
                'slug' => 'ustanovka-bokovogo',
                'price' => 6000,
                'glass_type' => GlassType::Side,
                'duration_minutes' => 60,
                'is_default' => true,
                'sort' => 30,
            ],
            [
                'name' => 'Установка форточки',
                'slug' => 'ustanovka-fortochki',
                'price' => 5000,
                'glass_type' => GlassType::Quarter,
                'duration_minutes' => 45,
                'is_default' => true,
                'sort' => 40,
            ],
            /*
             * Калибровка по умолчанию не предлагается: она нужна только там,
             * где за стеклом стоит камера, и решает это мастер после осмотра.
             * Но в прайсе она обязана быть — без неё ADAS после замены врёт.
             */
            [
                'name' => 'Калибровка камеры ADAS',
                'slug' => 'kalibrovka-adas',
                'price' => 20000,
                'glass_type' => GlassType::Windshield,
                'duration_minutes' => 90,
                'is_default' => false,
                'sort' => 50,
                'description' => 'Обязательна после замены стекла с камерой: без неё системы помощи водителю работают неверно.',
            ],
            [
                'name' => 'Выезд мастера по городу',
                'slug' => 'vyezd-mastera',
                'price' => 5000,
                'glass_type' => null,
                'duration_minutes' => 60,
                'is_default' => false,
                'sort' => 60,
            ],
        ];

        foreach ($services as $service) {
            // Цены правят из панели — сеялка их не перетирает
            Service::firstOrCreate(['slug' => $service['slug']], $service);
        }
    }
}
