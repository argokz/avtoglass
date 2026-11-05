<?php

namespace App\Support;

/**
 * WebP-варианты снимков товаров.
 *
 * В прайсах и внешних каталогах снимки приходят «как есть» — часто это
 * полноразмерный JPEG на пару мегабайт. Отдавать его в карточку на 400px
 * значит сжечь мобильный трафик покупателя и уронить LCP. Здесь из одного
 * оригинала делаются уменьшенные WebP, а шаблон отдаёт их через srcset.
 *
 * Зависимостей нет: хватает GD, который и так собран с PHP. Варианты
 * лежат в public-диске рядом с оригиналами и переживают деплой.
 */
class ImageVariants
{
    /** Ширины, под которые режем. Апскейл не делаем никогда. */
    public const WIDTHS = [480, 960, 1600];

    /** @var array<string,array<string,mixed>|null> кэш в пределах запроса */
    private array $resolved = [];

    /**
     * Готовый набор для <picture>: srcset, размеры оригинала и запасной URL.
     *
     * @return array{srcset:string,fallback:string,width:int,height:int}|null
     */
    public function for(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (array_key_exists($path, $this->resolved)) {
            return $this->resolved[$path];
        }

        $source = $this->absolutePath($path);

        if (! is_file($source)) {
            // Файла ещё нет (тесты, битая ссылка) — всё равно отдаём исходный URL,
            // иначе карточка молча теряет <img> и LCP-элемент пропадает.
            return $this->resolved[$path] = [
                'srcset' => '',
                'fallback' => файлВитрины($path),
                'width' => 800,
                'height' => 600,
            ];
        }

        $size = @getimagesize($source);

        if ($size === false) {
            return $this->resolved[$path] = [
                'srcset' => '',
                'fallback' => файлВитрины($path),
                'width' => 800,
                'height' => 600,
            ];
        }

        $srcset = [];

        foreach (self::WIDTHS as $width) {
            if ($width >= $size[0]) {
                continue;
            }

            $variant = $this->variantPath($path, $width);

            if (is_file($this->absolutePath($variant))) {
                $srcset[] = файлВитрины($variant).' '.$width.'w';
            }
        }

        return $this->resolved[$path] = [
            'srcset' => implode(', ', $srcset),
            'fallback' => файлВитрины($path),
            'width' => $size[0],
            'height' => $size[1],
        ];
    }

    /**
     * Режет варианты для одного снимка. Возвращает число созданных файлов.
     * Уже свежие варианты (mtime не старше оригинала) пропускаются.
     */
    public function generate(string $path): int
    {
        $source = $this->absolutePath($path);

        if (! is_file($source)) {
            return 0;
        }

        $info = @getimagesize($source);

        if ($info === false) {
            return 0;
        }

        [$width, $height, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => null,
        };

        if (! $image) {
            return 0;
        }

        $made = 0;

        foreach (self::WIDTHS as $targetWidth) {
            if ($targetWidth >= $width) {
                continue;
            }

            $target = $this->absolutePath($this->variantPath($path, $targetWidth));

            if (is_file($target) && filemtime($target) >= filemtime($source)) {
                continue;
            }

            $targetHeight = (int) max(1, round($height * ($targetWidth / $width)));
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);

            if (! $resized) {
                continue;
            }

            // Белая подложка: у PNG с прозрачностью без неё фон уходит в чёрный.
            imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            if (imagewebp($resized, $target, 82)) {
                $made++;
            }

            imagedestroy($resized);
        }

        imagedestroy($image);

        return $made;
    }

    /** Имя варианта не зависит от оригинала: путь может переехать, хвост останется. */
    public function variantPath(string $path, int $width): string
    {
        return 'variants/'.$width.'/'.md5($path).'.webp';
    }

    private function absolutePath(string $path): string
    {
        return storage_path('app/public/'.ltrim($path, '/'));
    }
}
