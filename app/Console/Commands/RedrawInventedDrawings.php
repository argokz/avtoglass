<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Стирает с готовых чертежей размеры, которых никто не мерил.
 *
 * Схемы рисовались от запасных 1400×800, когда настоящих габаритов не было,
 * и ставили на картинку подписи «W: 1400 мм». Поле в карточке при этом
 * оставалось пустым — мы вычистили выдумку из базы, но не из графики,
 * а покупатель читает цифру с рисунка и верит ей.
 *
 * Команда работает по готовым файлам: перерисовывать всё заново незачем,
 * убрать нужно ровно две размерные группы.
 */
class RedrawInventedDrawings extends Command
{
    protected $signature = 'catalog:redraw {--apply : переписать файлы}';

    protected $description = 'Убирает с чертежей размерные линии там, где габариты товара неизвестны';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $disk = Storage::disk('public');
        $счёт = ['выдумка' => 0, 'правда' => 0, 'нет файла' => 0];

        Product::query()
            ->whereNotNull('image_path')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($disk, $apply, &$счёт) {
                foreach ($products as $product) {
                    if (! $disk->exists($product->image_path)) {
                        $счёт['нет файла']++;

                        continue;
                    }

                    $svg = $disk->get($product->image_path);
                    $чистый = $this->стереть($svg, $product->width_mm, $product->height_mm);

                    if ($чистый === $svg) {
                        continue;
                    }

                    $счёт['выдумка']++;

                    if ($apply) {
                        $disk->put($product->image_path, $чистый);
                    }
                }
            });

        $счёт['правда'] = Product::whereNotNull('width_mm')->orWhereNotNull('height_mm')->count();

        $this->line('Чертежей с непроверенными размерами: '.$счёт['выдумка']);
        $this->line('Товаров с настоящими габаритами: '.$счёт['правда']);

        if ($счёт['нет файла'] > 0) {
            $this->warn('Карточек без файла: '.$счёт['нет файла']);
        }

        if (! $apply) {
            $this->warn('Запустите с --apply, чтобы переписать.');

            return self::SUCCESS;
        }

        $this->info('Переписано: '.$счёт['выдумка'].'.');

        return self::SUCCESS;
    }

    /**
     * Вырезает размерную группу, если соответствующего габарита у товара нет.
     * Группа опознаётся по комментарию, которым её пишет генератор.
     */
    private function стереть(string $svg, ?int $width, ?int $height): string
    {
        if ($width === null) {
            $svg = preg_replace('~\s*<!-- Линия ширины \(W\) -->.*?W: [^<]*</text>~su', '', $svg) ?? $svg;
        }

        if ($height === null) {
            $svg = preg_replace('~\s*<!-- Линия высоты \(H\) -->.*?H: [^<]*</text>~su', '', $svg) ?? $svg;
        }

        return $svg;
    }
}
