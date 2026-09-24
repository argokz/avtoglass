<?php

namespace App\Console\Commands;

use App\Services\Scraper\InternetMediaScraper;
use Illuminate\Console\Command;

/**
 * Консольная команда для выкачивания реальных медиафайлов из интернета:
 * 1. Логотипы всех марок (Toyota, BMW, Lexus, Mercedes, Hyundai, Kia и др.).
 * 2. Реальные фотографии всех моделей авто (Camry, RAV4, E-Class, X5, Solaris и др.).
 * 3. Логотипы производителей автостекол (Fuyao, AGC, XYG, Pilkington, Benson и др.).
 * 4. Чертежи автостекол с точными размерами (width_mm, height_mm).
 */
class ScrapeFromInternet extends Command
{
    protected $signature = 'catalog:scrape-internet
        {--brands : Выкачать логотипы марок авто}
        {--models : Выкачать реальные фото моделей авто}
        {--suppliers : Выкачать логотипы производителей автостекол}
        {--glasses : Выкачать/сгенерировать чертежи стекол с размерами}
        {--all : Выкачать абсолютно всё}
        {--limit=0 : Лимит для моделей и товаров (0 = без лимита)}
        {--force : Перезаписать существующие файлы}';

    protected $description = 'Скрапинг реальных медиафайлов, логотипов и фото из интернета';

    public function handle(InternetMediaScraper $scraper): int
    {
        $all = $this->option('all') || (! $this->option('brands') && ! $this->option('models') && ! $this->option('suppliers') && ! $this->option('glasses'));
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');

        $this->info('🌐 Запуск скрапера медиа из интернета...');

        // 1. Логотипы марок
        if ($all || $this->option('brands')) {
            $this->info('1. Скачивание логотипов марок авто из интернет-репозиториев...');
            $brandsCount = $scraper->scrapeBrandLogos($force);
            $this->line("  ✓ Успешно скачано и обновлено логотипов марок: {$brandsCount}");
        }

        // 2. Логотипы производителей автостекол
        if ($all || $this->option('suppliers')) {
            $this->info('2. Скачивание логотипов заводов автостекол...');
            $suppliersCount = $scraper->scrapeSupplierLogos($force);
            $this->line("  ✓ Успешно скачано логотипов производителей: {$suppliersCount}");
        }

        // 3. Фото моделей авто
        if ($all || $this->option('models')) {
            $this->info('3. Скачивание фотографий моделей (Wikipedia / Wikimedia Commons)...');
            $modelsCount = $scraper->scrapeModelPhotos($force, $limit);
            $this->line("  ✓ Скачано фотографий моделей: {$modelsCount}");
        }

        // 4. Чертежи стекол с размерами
        if ($all || $this->option('glasses')) {
            $this->info('4. Наполнение автостекол чертежами с размерами (Ширина x Высота)...');
            $glassStats = $scraper->scrapeGlassMedia($force, $limit);
            $this->line("  ✓ Создано чертежей стекол: {$glassStats['images']}, габаритов: {$glassStats['dimensions']}");
        }

        $this->newLine();
        $this->info('✨ Все данные и медиафайлы из интернета успешно загружены и привязаны к каталогу!');

        return self::SUCCESS;
    }
}
