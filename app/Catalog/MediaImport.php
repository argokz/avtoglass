<?php

namespace App\Catalog;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Загрузка снимков и характеристик из внешнего каталога.
 *
 * Источник намеренно не зашит. Ни один производитель — ни AGC, ни Xinyi,
 * ни Pilkington — не публикует открытый каталог, где по еврокоду отдаётся
 * фотография: их сайты рекламные, а настоящие базы (ARGIC и кабинеты
 * поставщиков) закрыты логином. Значит, файл с фотографиями и параметрами
 * компания получает сама — у поставщика, у представителя завода или снимая
 * ходовые позиции. Задача кода — принять этот файл и разложить по карточкам,
 * каким бы он ни пришёл.
 *
 * Сопоставление по коду, а не по названию: названия у поставщиков разные,
 * а еврокод и артикул — общий язык отрасли.
 */
class MediaImport
{
    /** Поля, которые можно донести из внешнего каталога. */
    private const FIELDS = ['width_mm', 'height_mm', 'holes_count', 'tint', 'oem_code', 'description'];

    /**
     * @param  Collection<int,array{code:string,image?:?string,...}>  $entries
     * @return array{matched:int,images:int,fields:int,unmatched:array<int,string>}
     */
    public function apply(Collection $entries, bool $dryRun = true): array
    {
        $index = $this->indexProducts();

        $matched = 0;
        $images = 0;
        $fields = 0;
        $unmatched = [];

        foreach ($entries as $entry) {
            $key = $this->normalize($entry['code'] ?? '');
            $productId = $index[$key] ?? null;

            if ($productId === null) {
                $unmatched[] = $entry['code'] ?? '';

                continue;
            }

            $matched++;

            if ($dryRun) {
                continue;
            }

            $product = Product::find($productId);

            if ($product === null) {
                continue;
            }

            $images += $this->attachImage($product, $entry['image'] ?? null);
            $fields += $this->fillFields($product, $entry);
        }

        return [
            'matched' => $matched,
            'images' => $images,
            'fields' => $fields,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Указатель «код -> карточка» строим один раз.
     *
     * Кодов у карточки может быть три — еврокод, артикул поставщика и код OEM.
     * Внешний каталог назовёт какой-то один, и заранее неизвестно какой.
     *
     * @return array<string,int>
     */
    private function indexProducts(): array
    {
        $index = [];

        Product::query()
            ->select(['id', 'eurocode', 'internal_code', 'oem_code'])
            ->chunkById(1000, function ($chunk) use (&$index) {
                foreach ($chunk as $product) {
                    foreach ([$product->eurocode, $product->internal_code, $product->oem_code] as $code) {
                        $key = $this->normalize((string) $code);

                        // Первый выигрывает: если один код у двух карточек,
                        // угадывать, какая нужна, нельзя — лучше не трогать вторую.
                        if ($key !== '' && ! isset($index[$key])) {
                            $index[$key] = $product->id;
                        }
                    }
                }
            });

        return $index;
    }

    /** «4502 AGS» и «4502ags» — один и тот же код. */
    private function normalize(string $code): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/u', '', $code) ?? '');
    }

    /**
     * Кладёт снимок в публичное хранилище.
     *
     * Уже загруженное не трогаем: менеджер мог заменить заводскую картинку
     * своей фотографией, и внешний каталог не должен её затирать.
     */
    private function attachImage(Product $product, ?string $source): int
    {
        if ($source === null || filled($product->image_path) || ! is_file($source)) {
            return 0;
        }

        $extension = mb_strtolower(pathinfo($source, PATHINFO_EXTENSION)) ?: 'jpg';
        $path = 'products/'.$product->id.'-'.substr(md5_file($source) ?: '', 0, 8).'.'.$extension;

        Storage::disk('public')->put($path, file_get_contents($source));
        $product->update(['image_path' => $path]);

        return 1;
    }

    /** Заполняем только пустое — по тому же правилу, что и везде в каталоге. */
    private function fillFields(Product $product, array $entry): int
    {
        $updates = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $entry) || blank($entry[$field]) || filled($product->$field)) {
                continue;
            }

            $updates[$field] = $entry[$field];
        }

        if ($updates === []) {
            return 0;
        }

        $product->update($updates);

        return count($updates);
    }
}
