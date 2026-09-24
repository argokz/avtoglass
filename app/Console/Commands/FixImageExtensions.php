<?php

namespace App\Console\Commands;

use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Приводит картинки в соответствие с тем, чем они на самом деле являются.
 *
 * Две беды, обе видны покупателю одинаково — пустой рамкой:
 *
 *  1. Расширение врёт. Схемы стёкол — это SVG, а сохранялись они с именем
 *     .png. Сервер отдавал их как image/png, и не показалась ни одна из
 *     без малого шести тысяч.
 *
 *  2. Файл вообще не картинка. Качалка логотипов получала от источника
 *     страницу «404» и сохраняла её как brands/vaz.svg. На диске файл есть,
 *     в базе ссылка есть, на витрине — дыра.
 *
 * Первое чиним переименованием, второе — удалением ссылки: у марок и
 * поставщиков есть нарисованная замена, и честная заглушка лучше пустоты.
 */
class FixImageExtensions extends Command
{
    protected $signature = 'catalog:fix-images {--apply : переименовать файлы и обновить карточки}';

    protected $description = 'Чинит расширение картинок и убирает ссылки на файлы, которые картинками не являются';

    /** @var list<array{0:Model,1:string,2:string}> путь-к-полю, что делать */
    private array $переименовать = [];

    /** @var list<array{0:Model,1:string}> */
    private array $отвязать = [];

    private int $потеряны = 0;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->осмотреть(Product::query()->whereNotNull('image_path'), 'image_path');
        $this->осмотреть(CarModel::query()->whereNotNull('image_path'), 'image_path');
        $this->осмотреть(CarBrand::query()->whereNotNull('logo_path'), 'logo_path');
        $this->осмотреть(Supplier::query()->whereNotNull('logo_path'), 'logo_path');

        if ($this->потеряны > 0) {
            $this->warn("Ссылок на пропавший файл: {$this->потеряны}.");
        }

        if ($this->переименовать === [] && $this->отвязать === []) {
            $this->info('Все картинки на месте и названы верно.');

            return self::SUCCESS;
        }

        $this->отчёт();

        if (! $apply) {
            $this->warn('Запустите с --apply, чтобы применить.');

            return self::SUCCESS;
        }

        $this->info('Переименовано: '.$this->применитьПереименование().'.');
        $this->info('Отвязано нерабочих файлов: '.$this->применитьОтвязку().'.');

        return self::SUCCESS;
    }

    /** @param  Builder<Model>  $query */
    private function осмотреть($query, string $field): void
    {
        $disk = Storage::disk('public');

        $query->orderBy('id')->chunkById(500, function ($records) use ($disk, $field) {
            foreach ($records as $record) {
                $path = (string) $record->{$field};

                if (! $disk->exists($path)) {
                    $this->потеряны++;

                    continue;
                }

                $вид = $this->видФайла($disk->get($path));

                if ($вид === null) {
                    $this->отвязать[] = [$record, $field];

                    continue;
                }

                if ($вид !== mb_strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                    $this->переименовать[] = [$record, $field, $вид];
                }
            }
        });
    }

    private function отчёт(): void
    {
        if ($this->переименовать !== []) {
            $this->line('Расширение не совпадает с содержимым: '.count($this->переименовать));
            $this->table(
                ['Что', 'Файл', 'Станет'],
                array_map(fn ($row) => [
                    class_basename($row[0]),
                    $row[0]->{$row[1]},
                    $row[2],
                ], array_slice($this->переименовать, 0, 5))
            );
        }

        if ($this->отвязать !== []) {
            $this->line('Файлы, которые не являются картинками: '.count($this->отвязать));
            $this->table(
                ['Что', 'Название', 'Файл'],
                array_map(fn ($row) => [
                    class_basename($row[0]),
                    mb_substr((string) ($row[0]->name ?? ''), 0, 30),
                    $row[0]->{$row[1]},
                ], array_slice($this->отвязать, 0, 15))
            );
        }
    }

    private function применитьПереименование(): int
    {
        $disk = Storage::disk('public');
        $готово = 0;

        foreach ($this->переименовать as [$record, $field, $ext]) {
            $было = (string) $record->{$field};
            $стало = preg_replace('/\.[^.]+$/', '.'.$ext, $было);

            /*
             * Сначала файл, потом ссылка: если переименование не удалось,
             * запись продолжает указывать на существующий файл, а не в пустоту.
             */
            if (! $disk->exists($стало) && ! $disk->move($было, $стало)) {
                $this->error('Не удалось переименовать '.$было);

                continue;
            }

            $record->forceFill([$field => $стало])->save();
            $готово++;
        }

        return $готово;
    }

    private function применитьОтвязку(): int
    {
        $disk = Storage::disk('public');
        $готово = 0;

        foreach ($this->отвязать as [$record, $field]) {
            $disk->delete((string) $record->{$field});
            $record->forceFill([$field => null])->save();
            $готово++;
        }

        return $готово;
    }

    /** Возвращает расширение по содержимому или null, если это вообще не картинка. */
    private function видФайла(string $binary): ?string
    {
        $head = ltrim(mb_substr($binary, 0, 512));

        if (str_starts_with($head, '<svg') || (str_starts_with($head, '<?xml') && str_contains($head, '<svg'))) {
            return 'svg';
        }

        return match (true) {
            str_starts_with($binary, "\x89PNG") => 'png',
            str_starts_with($binary, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($binary, 'GIF8') => 'gif',
            str_starts_with($binary, 'RIFF') && str_contains(substr($binary, 0, 16), 'WEBP') => 'webp',
            default => null,
        };
    }
}
