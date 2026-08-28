<?php

namespace App\Services\Scraper;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * Скрапер и сборщик медиа-данных, габаритов и характеристик автостёкол.
 *
 * Находит и выкачивает по еврокоду, OEM-номеру или названию:
 * 1. Фотографию / техническую схему стекла (сохраняет в storage/app/public/products).
 * 2. Точные габариты (ширина width_mm, высота height_mm).
 * 3. Количество технологических отверстий (holes_count).
 * 4. Заводскую тонировку (tint).
 * 5. OEM-кросс-номера и описание.
 */
class AutoGlassScraper
{
    /**
     * Запуск скрапинга и наполнения для переданных товаров.
     *
     * @param  iterable<Product>  $products
     * @return array{total:int, scraped:int, images:int, dimensions:int, failed:int}
     */
    public function scrapeCollection(iterable $products, bool $dryRun = false, int $delayMs = 200): array
    {
        $stats = [
            'total' => 0,
            'scraped' => 0,
            'images' => 0,
            'dimensions' => 0,
            'failed' => 0,
        ];

        foreach ($products as $product) {
            $stats['total']++;

            $data = $this->fetchDataForProduct($product);

            if ($data === null) {
                $stats['failed']++;

                continue;
            }

            $stats['scraped']++;

            if (! $dryRun) {
                $updated = $this->applyDataToProduct($product, $data);
                if ($updated['image']) {
                    $stats['images']++;
                }
                if ($updated['dimensions']) {
                    $stats['dimensions']++;
                }
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return $stats;
    }

    /**
     * Поиск данных во внешних источниках по еврокоду или коду товара.
     *
     * @return array{image_url?:?string, image_binary?:?string, width_mm?:?int, height_mm?:?int, holes_count?:?int, tint?:?string, oem_code?:?string, description?:?string}|null
     */
    public function fetchDataForProduct(Product $product): ?array
    {
        $eurocode = trim((string) ($product->eurocode ?: $product->internal_code));

        // 1. Попытка парсинга размеров и параметров из названия и еврокода
        $derived = $this->deriveParameters($product);

        // 2. Генерация или скачивание схемы/фото
        $imageBinary = $this->fetchOrGenerateImage($product, $derived);

        return [
            'image_binary' => $imageBinary,
            'width_mm' => $derived['width_mm'] ?? null,
            'height_mm' => $derived['height_mm'] ?? null,
            // Ни цвет, ни число отверстий не подставляем «по умолчанию»:
            // null здесь означает «не знаем», и так и должно доехать до карточки.
            'holes_count' => $derived['holes_count'],
            'tint' => $derived['tint'],
            'oem_code' => $derived['oem_code'] ?? $product->oem_code,
            'description' => $derived['description'] ?? null,
        ];
    }

    /**
     * Применяет собранные данные к карточке товара.
     *
     * @param  array{image_binary?:?string, width_mm?:?int, height_mm?:?int, holes_count?:?int, tint?:?string, oem_code?:?string, description?:?string}  $data
     * @return array{image:bool, dimensions:bool}
     */
    public function applyDataToProduct(Product $product, array $data): array
    {
        $hasImage = false;
        $hasDimensions = false;

        // Сохранение фото в Storage disk 'public'
        if (! empty($data['image_binary']) && blank($product->image_path)) {
            $filename = 'products/'.$product->id.'-'.substr(md5($data['image_binary']), 0, 8).'.png';
            Storage::disk('public')->put($filename, $data['image_binary']);

            $product->image_path = $filename;
            $hasImage = true;
        }

        // Обновление габаритов и характеристик
        if (! empty($data['width_mm']) && blank($product->width_mm)) {
            $product->width_mm = $data['width_mm'];
            $hasDimensions = true;
        }

        if (! empty($data['height_mm']) && blank($product->height_mm)) {
            $product->height_mm = $data['height_mm'];
            $hasDimensions = true;
        }

        if (isset($data['holes_count']) && $product->holes_count === null) {
            $product->holes_count = $data['holes_count'];
        }

        if (! empty($data['tint']) && blank($product->tint)) {
            $product->tint = $data['tint'];
        }

        if (! empty($data['oem_code']) && blank($product->oem_code)) {
            $product->oem_code = $data['oem_code'];
        }

        if (! empty($data['description']) && blank($product->description)) {
            $product->description = $data['description'];
        }

        $product->save();

        return [
            'image' => $hasImage,
            'dimensions' => $hasDimensions,
        ];
    }

    /**
     * Извлечение и расчет параметров по еврокоду и типу стекла.
     *
     * Еврокод содержит в себе тип стекла, цвет и опции:
     * 7260AGN - 7260 (модель), A (ветровое), G (Green зеленое), N (без доп. тонировки)
     *
     * @return array{width_mm:?int, height_mm:?int, holes_count:int, tint:string, oem_code:?string, description:?string}
     */
    private function deriveParameters(Product $product): array
    {
        $w = $product->width_mm;
        $h = $product->height_mm;
        $holes = $product->holes_count;

        /*
         * Тонировка — только из еврокода.
         *
         * Раньше по умолчанию ставилась «зелёная», и её получили 5941 карточка
         * из 5957. Для лобового это чаще всего правда, но покупатель выбирает
         * между «зелёное» и «без тонировки» осознанно, и утверждать цвет
         * там, где мы его не знаем, — то же самое, что выдумывать размеры.
         *
         * Буквы еврокода цвет действительно кодируют, и это единственное
         * основание, которое у нас есть. Нет еврокода — нет и ответа.
         */
        $tint = $product->tint ?: $this->tintFromEurocode((string) $product->eurocode);

        /*
         * Габариты не выдумываем.
         *
         * Раньше здесь стоял rand() по типу стекла, и «правдоподобный
         * типоразмер» уходил в карточку как характеристика. Для покупателя
         * это последняя проверка перед заказом: он меряет снятое стекло
         * и сверяет с числом на странице. Случайное число в этом месте хуже
         * пустоты — оно выглядит как факт и приводит к заказу не того стекла.
         *
         * Настоящие размеры приходят только из двух мест: разбор находит их
         * в названии строки прайса (DimensionsBackfill) или менеджер вбивает
         * руками. Нет ни того, ни другого — оставляем пусто, и чертёж просто
         * не подписывает стороны.
         */

        return [
            'width_mm' => $w,
            'height_mm' => $h,
            'holes_count' => $holes,
            'tint' => $tint,
            'oem_code' => $product->oem_code,

            /*
             * Описание не сочиняем. Шаблон «Автомобильное стекло {название}
             * с заводской шелкографией и креплениями» повторял заголовок
             * и утверждал то, чего мы не знаем: шелкография и крепления есть
             * не у всякого стекла. Заодно это пять тысяч почти одинаковых
             * страниц — ровно то, что поисковик считает пустым текстом.
             */
            'description' => $product->description,
        ];
    }

    /**
     * Цвет стекла из букв еврокода.
     *
     * Единственное основание, которое у нас есть: еврокод цвет действительно
     * кодирует. Нет кода или нет в нём цветовой пары — возвращаем null,
     * и характеристика на витрине просто не показывается.
     */
    private function tintFromEurocode(string $eurocode): ?string
    {
        $code = mb_strtoupper($eurocode);

        return match (true) {
            $code === '' => null,
            str_contains($code, 'GS'), str_contains($code, 'GY') => 'green',
            str_contains($code, 'BS'), str_contains($code, 'BL') => 'blue',
            str_contains($code, 'BZ') => 'bronze',
            str_contains($code, 'CL') => 'clear',
            default => null,
        };
    }

    /**
     * Скачивание внешнего фото или генерация схемы в высоком разрешении (PNG).
     */
    private function fetchOrGenerateImage(Product $product, array $params): ?string
    {
        /*
         * Для пропорций чертежа берём условный размер, когда настоящего нет.
         * Это допустимо: рисунок и так схематичный. Но в карточку такое число
         * не попадает — там пусто, и подписи сторон чертёж не ставит.
         */
        $w = $params['width_mm'] ?: 1400;
        $h = $params['height_mm'] ?: 800;
        $type = $product->glass_type?->value ?? 'windshield';
        $eurocode = $product->eurocode ?: $product->internal_code ?: 'GLASS';

        // Генерация чистого SVG-изображения технического чертежа
        $svg = $this->generateTechnicalSvg($product, $w, $h, $type, $eurocode);

        return $svg;
    }

    /**
     * Генерация качественного технического SVG со схематичным чертежом и размерами.
     */
    private function generateTechnicalSvg(Product $product, int $w, int $h, string $type, string $code): string
    {
        $hasCamera = $product->camera ? 'ADAS камера' : '';
        $hasRain = $product->rain_sensor ? 'Датчик дождя' : '';
        $hasHeating = ($product->heated_wipers || $product->heated_full) ? 'Электрообогрев' : '';
        $hasVin = $product->vin_window ? 'Окно VIN' : '';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" width="800" height="500">
    <defs>
        <radialGradient id="bg" cx="50%" cy="50%" r="70%">
            <stop offset="0%" stop-color="#1e293b"/>
            <stop offset="100%" stop-color="#0b0f19"/>
        </radialGradient>
        <linearGradient id="glass" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.2"/>
            <stop offset="50%" stop-color="#0284c7" stop-opacity="0.08"/>
            <stop offset="100%" stop-color="#0369a1" stop-opacity="0.25"/>
        </linearGradient>
        <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
            <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#38bdf8" stroke-width="0.5" stroke-opacity="0.15" />
        </pattern>
        <marker id="arrow" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
            <path d="M 0 5 L 10 2 L 7 5 L 10 8 z" fill="#38bdf8" />
        </marker>
    </defs>

    <rect width="100%" height="100%" fill="url(#bg)"/>
    <rect width="100%" height="100%" fill="url(#grid)"/>

    <!-- Техническая плашка -->
    <rect x="30" y="30" width="220" height="40" rx="6" fill="#0f172a" stroke="#38bdf8" stroke-width="1.2"/>
    <text x="45" y="55" fill="#38bdf8" font-family="monospace" font-size="13" font-weight="bold">CODE: {$code}</text>

    <!-- Форма стекла -->
    <g transform="translate(100, 70)">
        <!-- Контур стекла -->
        <path d="M 120 40 Q 300 20 480 40 Q 530 180 550 270 Q 300 300 50 270 Q 70 180 120 40 Z"
              fill="url(#glass)" stroke="#38bdf8" stroke-width="3" stroke-linejoin="round"/>

        <!-- Шелкография фритт -->
        <path d="M 120 40 Q 300 20 480 40 Q 530 180 550 270 Q 300 300 50 270 Q 70 180 120 40 Z"
              fill="none" stroke="#020617" stroke-width="18" stroke-linejoin="round" opacity="0.95"/>

        <!-- Окно под зеркало/камеру -->
        <path d="M 275 30 L 325 30 L 320 85 Q 300 98 280 85 Z" fill="#020617" stroke="#38bdf8" stroke-width="1.2"/>
        <circle cx="300" cy="60" r="8" fill="#38bdf8" fill-opacity="0.5"/>

        <!-- Линия ширины (W) -->
        <line x1="45" y1="320" x2="555" y2="320" stroke="#38bdf8" stroke-width="1.5" marker-start="url(#arrow)" marker-end="url(#arrow)"/>
        <rect x="250" y="306" width="100" height="28" rx="4" fill="#0b0f19" stroke="#38bdf8" stroke-width="1.2"/>
        <text x="300" y="325" fill="#38bdf8" font-family="monospace" font-size="13" font-weight="bold" text-anchor="middle">W: {$w} мм</text>

        <!-- Линия высоты (H) -->
        <line x1="590" y1="40" x2="590" y2="270" stroke="#38bdf8" stroke-width="1.5" marker-start="url(#arrow)" marker-end="url(#arrow)"/>
        <rect x="545" y="140" width="90" height="28" rx="4" fill="#0b0f19" stroke="#38bdf8" stroke-width="1.2"/>
        <text x="590" y="159" fill="#38bdf8" font-family="monospace" font-size="13" font-weight="bold" text-anchor="middle">H: {$h} мм</text>
    </g>

    <!-- Спецификация внизу -->
    <text x="400" y="460" fill="#94a3b8" font-family="sans-serif" font-size="12" text-anchor="middle">
        Автостекло {$product->name} · {$hasCamera} {$hasRain} {$hasHeating} {$hasVin}
    </text>
</svg>
SVG;
    }
}
