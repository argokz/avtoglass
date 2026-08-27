<?php

namespace App\Console\Commands;

use App\Catalog\CatalogCounters;
use App\Catalog\FitmentYears;
use App\Catalog\ModelNormalizer;
use App\Catalog\NormalizedModel;
use App\Catalog\PublishingGuard;
use App\Enums\GlassType;
use App\Models\CarBrand;
use App\Models\CarGeneration;
use App\Models\CarModel;
use App\Models\CarModelAlias;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Сборка каталога из разобранного стейджинга.
 *
 * Главное здесь — склейка: 6937 строк прайса это не 6937 товаров.
 * Одно и то же стекло приходит от нескольких поставщиков, и это должно стать
 * одной карточкой с несколькими предложениями, иначе покупатель видит
 * пять почти одинаковых позиций и не понимает, чем они отличаются.
 */
class BuildCatalog extends Command
{
    protected $signature = 'catalog:build
        {run? : ID импорта, по умолчанию последний}
        {--fresh : Очистить каталог перед сборкой}';

    protected $description = 'Собрать каталог (товары, предложения, применимость) из стейджинга';

    /**
     * Поставщики: диалект прайса => как показывать и в какой ценовой класс относить.
     * markup — наценка от оптовой цены к розничной. Значения предварительные,
     * их надо заменить на реальные правила ценообразования.
     */
    private const SUPPLIERS = [
        'kmk' => ['КМК',        'standard', 35],
        'sl' => ['SL',         'standard', 35],
        'spektr' => ['СПЕКТР',     'economy',  40],
        'bgc' => ['BGC',        'standard', 35],
        'xyg' => ['XYG',        'premium',  25],
        'agc' => ['AGC',        'oem',      20],
        'nordglass' => ['NordGlass',  'premium',  25],
        'benson' => ['Benson',     'premium',  25],
        'fuyao' => ['Fuyao',      'premium',  25],
        'pilkington' => ['Pilkington', 'oem',      20],
        'bor' => ['BOR',        'standard', 35],
        'china' => ['Китай',      'economy',  40],
        'oem' => ['Оригинал',   'oem',      20],
        'unknown' => ['Не указан',  'standard', 35],
    ];

    /**
     * Ниже этой цены стекло не бывает: самое дешёвое боковое в выгрузке стоит
     * около трёх тысяч. Всё, что дешевле, — ошибка в прайсе, а не находка.
     */
    private const MIN_PLAUSIBLE_GLASS_PRICE = 1000;

    /** Короткие обозначения опций для названия карточки. */
    private const OPTION_SHORT = [
        'sun_band' => 'ЗП',
        'rain_sensor' => 'ДД',
        'camera' => 'камера',
        'heated_wipers' => 'обогрев щёток',
        'heated_full' => 'полный обогрев',
        'heated_rear' => 'обогрев',
        'antenna' => 'антенна',
        'hud' => 'HUD',
        'acoustic' => 'акустика',
        'atermal' => 'атермальное',
        'silkscreen' => 'шелкография',
        'rhd' => 'правый руль',
        'mirror_bracket' => 'держатель зеркала',
        'vin_window' => 'окно VIN',
    ];

    /** @var array<string,Supplier> держим в памяти: иначе на каждую строку прайса свой запрос */
    private array $suppliers = [];

    private array $brandIds = [];

    private array $modelIds = [];

    /** @var array<string,int> */
    private array $generationIds = [];

    private ModelNormalizer $normalizer;

    public function handle(): int
    {
        $run = $this->argument('run')
            ? PriceImportRun::find($this->argument('run'))
            : PriceImportRun::latest('id')->first();

        if (! $run) {
            $this->error('Импорт не найден. Сначала выполните price:import.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->truncateCatalog();
        }

        $this->seedSuppliers();

        // Псевдонимы читаем один раз: их десятки, а строк прайса тысячи.
        $this->normalizer = CarModelAlias::normalizer();

        $rows = PriceImportRow::where('price_import_run_id', $run->id)
            ->whereNotNull('brand_name')
            ->whereNotNull('glass_type');

        $total = $rows->count();
        $skipped = PriceImportRow::where('price_import_run_id', $run->id)->count() - $total;

        $this->info("Сборка каталога из импорта #{$run->id}: {$total} строк");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = ['products' => 0, 'offers' => 0, 'fitments' => 0, 'review' => 0];

        $rows->orderBy('id')->chunkById(500, function ($chunk) use (&$stats, $bar) {
            DB::transaction(function () use ($chunk, &$stats, $bar) {
                foreach ($chunk as $row) {
                    $this->processRow($row, $stats);
                    $bar->advance();
                }
            });
        });

        $bar->finish();
        $this->newLine(2);

        $this->refreshPricing();

        $run->update(['status' => 'applied']);

        $this->report($stats, $skipped);

        return self::SUCCESS;
    }

    private function processRow(PriceImportRow $row, array &$stats): void
    {
        $options = $this->normalizeOptions((array) $row->options);
        $signature = $this->signature($row, $options);

        $product = Product::where('signature', $signature)->first();

        if (! $product) {
            $product = $this->createProduct($row, $options, $signature);
            $stats['products']++;

            if ($product->needs_review) {
                $stats['review']++;
            }
        } elseif ($row->status === 'review' && ! $product->needs_review) {
            // карточка уже есть, но эта строка сомнительная — помечаем на проверку
            $product->update(['needs_review' => true, 'is_published' => false]);
        }

        $this->createOffer($product, $row);
        $stats['offers']++;

        $stats['fitments'] += $this->createFitments($product, $row);

        $row->update(['product_id' => $product->id, 'status' => 'applied']);
    }

    /**
     * Отпечаток карточки. Годы входят в подпись, потому что стекло для рестайлинга
     * физически другое, а вот поставщик и цена — нет: на этом и работает склейка.
     */
    private function signature(PriceImportRow $row, array $options): string
    {
        $parts = [
            mb_strtolower(trim((string) $row->brand_name)),
            mb_strtolower(trim((string) $row->model_name)),
            $row->glass_type,
            // сторона обязательна в подписи: левое и правое боковое — разные товары,
            // склеить их значит отгрузить клиенту не то стекло
            $row->position,
            $row->year_from,
            $row->year_to,
            $row->body_type,
            $row->doors,
            $row->eurocode ? mb_strtolower($row->eurocode) : null,
            implode(',', array_keys(array_filter($options))),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /** Приводим набор опций к полному списку флагов, чтобы подпись была стабильной. */
    private function normalizeOptions(array $raw): array
    {
        $options = [];

        foreach (array_keys(self::OPTION_SHORT) as $flag) {
            $options[$flag] = (bool) ($raw[$flag] ?? false);
        }

        return $options;
    }

    private function createProduct(PriceImportRow $row, array $options, string $signature): Product
    {
        $type = GlassType::from($row->glass_type);
        $name = $this->productName($row, $type, $options);
        $needsReview = $row->status === 'review';

        return Product::create([
            'signature' => $signature,
            'slug' => $this->uniqueSlug($name, $signature),
            'name' => $name,
            'glass_type' => $type->value,
            'position' => $row->position,
            'eurocode' => $row->eurocode,
            'internal_code' => $row->internal_code,
            'tint' => null,

            // Габариты покупатель сверяет со снятым стеклом, когда машину
            // не удалось определить по названию, — для него это последняя проверка.
            'width_mm' => $row->width_mm,
            'height_mm' => $row->height_mm,
            'holes_count' => $row->holes_count,
            'needs_review' => $needsReview,
            // публикуем только то, в чём уверены: без картинок и цен витрине нечего показывать
            'is_published' => ! $needsReview && $row->price !== null,
            ...$options,
        ]);
    }

    private function productName(PriceImportRow $row, GlassType $type, array $options): string
    {
        $side = match ($row->position) {
            'left' => 'левое',
            'right' => 'правое',
            default => null,
        };

        $parts = [$type->label(), $side, $row->brand_name, $row->model_name];

        if ($row->year_from) {
            $parts[] = $row->year_to
                ? "{$row->year_from}–{$row->year_to}"
                : "{$row->year_from}–н.в.";
        }

        $name = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts))));

        $short = [];
        foreach ($options as $flag => $enabled) {
            if ($enabled) {
                $short[] = self::OPTION_SHORT[$flag];
            }
        }

        return $short ? $name.' ('.implode(', ', $short).')' : $name;
    }

    private function uniqueSlug(string $name, string $signature): string
    {
        $base = Str::limit(Str::slug($name), 180, '');

        if ($base === '') {
            $base = 'steklo';
        }

        // короткий хвост подписи гарантирует уникальность без запросов в цикле
        return $base.'-'.substr($signature, 0, 8);
    }

    private function createOffer(Product $product, PriceImportRow $row): void
    {
        $supplier = $this->suppliers[$row->dialect] ?? $this->suppliers['unknown'];
        $supplierId = $supplier->id;

        // Округляем до десятков, но никогда не вниз от закупки: на копеечных
        // позициях (скотч, лезвия) round(-1) даёт ноль, и товар уходит в
        // витрину с ценой 0 ₸.
        $retail = null;

        if ($row->price !== null) {
            $wholesale = (float) $row->price;
            $retail = round($wholesale * (1 + (float) $supplier->markup_percent / 100), -1);

            if ($retail < $wholesale) {
                $retail = ceil($wholesale / 10) * 10;
            }
        }

        Offer::updateOrCreate(
            [
                'product_id' => $product->id,
                'supplier_id' => $supplierId,
                'article' => $row->raw_article,
            ],
            [
                'raw_name' => $row->raw_name,
                'price_wholesale' => $row->price,
                'price_retail' => $retail,
                'currency' => 'KZT',
                'warranty_months' => $supplier->warranty_months,
                // остатков в выгрузке нет — до интеграции с 1С всё «под заказ»
                'stock_qty' => 0,
                'is_active' => $row->price !== null,
                'price_updated_at' => now(),
            ]
        );
    }

    /** Применимость: основная машина плюс все co-модели из "Berlingo // Partner". */
    private function createFitments(Product $product, PriceImportRow $row): int
    {
        $created = 0;

        $targets = [['brand' => $row->brand_name, 'model' => $row->model_name]];

        foreach ((array) $row->co_models as $co) {
            $targets[] = [
                'brand' => $co['brand'] ?: $row->brand_name,
                'model' => $co['model'] ?: null,
            ];
        }

        foreach ($targets as $target) {
            if (! $target['brand'] || ! $target['model']) {
                continue;
            }

            $normalized = $this->normalizer->normalize($target['brand'], $target['model']);
            $modelId = $this->resolveModel($target['brand'], $normalized);

            Fitment::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'car_model_id' => $modelId,
                    'year_from' => $row->year_from,
                    'year_to' => $row->year_to,
                    'body_type' => $row->body_type,
                ],
                [
                    'doors' => $row->doors,
                    'car_generation_id' => $this->resolveGeneration($modelId, $normalized, $row),
                ]
            );

            $created++;
        }

        return $created;
    }

    private function resolveModel(string $brandName, NormalizedModel $model): int
    {
        $brandKey = mb_strtolower(trim($brandName));
        $modelKey = $brandKey.'|'.$model->key();

        if (isset($this->modelIds[$modelKey])) {
            return $this->modelIds[$modelKey];
        }

        if (! isset($this->brandIds[$brandKey])) {
            $brand = CarBrand::firstOrCreate(
                ['name' => trim($brandName)],
                ['slug' => Str::slug($brandName) ?: 'brand-'.md5($brandKey)]
            );

            $this->brandIds[$brandKey] = $brand->id;
        }

        // Дефис внутри слова у поставщиков произволен: «4-Runner» и «4Runner»,
        // «CR-V» и «CRV» — одна машина. Слаг склеивает их в одну модель.
        $slug = Str::slug(str_replace('-', '', $model->name))
            ?: 'model-'.substr(md5($modelKey), 0, 8);

        $record = CarModel::firstOrCreate(
            ['car_brand_id' => $this->brandIds[$brandKey], 'slug' => $slug],
            ['name' => $model->name]
        );

        return $this->modelIds[$modelKey] = $record->id;
    }

    /**
     * Поколение живёт под моделью и опознаётся по коду («II», «E39», «40»).
     * Годы берём из строки прайса: в справочнике они пригодятся мастеру подбора,
     * чтобы отсечь неподходящие годы выпуска.
     */
    private function resolveGeneration(int $modelId, NormalizedModel $model, PriceImportRow $row): ?int
    {
        if ($model->generation === null) {
            return null;
        }

        $key = $modelId.'|'.mb_strtolower($model->generation);

        if (isset($this->generationIds[$key])) {
            return $this->generationIds[$key];
        }

        $generation = CarGeneration::firstOrCreate(
            ['car_model_id' => $modelId, 'code' => $model->generation],
            [
                'name' => $model->generation,
                'year_from' => $row->year_from,
                'year_to' => $row->year_to,
            ]
        );

        return $this->generationIds[$key] = $generation->id;
    }

    private function seedSuppliers(): void
    {
        foreach (self::SUPPLIERS as $dialect => [$name, $tier, $markup]) {
            $supplier = Supplier::firstOrCreate(
                ['slug' => Str::slug($name) ?: $dialect],
                [
                    'name' => $name,
                    'tier' => $tier,
                    'markup_percent' => $markup,
                    'warranty_months' => $tier === 'oem' ? 24 : 12,
                ]
            );

            $this->suppliers[$dialect] = $supplier;
        }
    }

    /**
     * Витринные цены и наличие — одним запросом.
     * Перебор в PHP на 5000+ карточках даёт десятки тысяч запросов и минуты работы.
     */
    private function refreshPricing(): void
    {
        $this->info('Пересчёт витринных цен...');

        /*
         * Булевы колонки сравниваем и присваиваем как булевы, а не как 1/0.
         * SQLite проглотит любой вариант, PostgreSQL — нет: там boolean это
         * отдельный тип, и «is_active = 1» падает с ошибкой приведения.
         */
        DB::statement('
            UPDATE products SET
                price_min = (
                    SELECT MIN(price_retail) FROM offers
                    WHERE offers.product_id = products.id AND offers.is_active = true
                ),
                price_max = (
                    SELECT MAX(price_retail) FROM offers
                    WHERE offers.product_id = products.id AND offers.is_active = true
                ),
                in_stock = (
                    SELECT COALESCE(SUM(stock_qty), 0) > 0
                    FROM offers
                    WHERE offers.product_id = products.id AND offers.is_active = true
                )
        ');

        $hidden = (new PublishingGuard)->apply();

        if ($hidden > 0) {
            $this->warn("Снято с витрины из-за неправдоподобной цены: {$hidden}");
        }

        // Годы разносим после разбора всех строк: поколение узнаёт свои годы
        // из той строки прайса, где диапазон был написан, а она может прийти
        // позже той, где стоит только «Camry 70».
        $years = (new FitmentYears)->backfill();

        if ($years > 0) {
            $this->line("Уточнено годов применимости по поколению: {$years}");
        }

        $this->refreshCounters();
    }

    /**
     * Счётчики для меню каталога. Считаем только опубликованное: в меню не должно
     * быть марки, которая ведёт на пустую страницу.
     *
     * Осторожно с именами: колонка models_count совпадает с алиасом, который
     * генерирует withCount('models'), и тихо перебивает его значением из таблицы.
     * Поэтому счётчики обязаны быть заполнены, а не просто существовать.
     */
    private function refreshCounters(): void
    {
        (new CatalogCounters)->refresh();
    }

    private function truncateCatalog(): void
    {
        $this->warn('Очистка каталога...');

        DB::table('fitments')->delete();
        DB::table('offers')->delete();
        DB::table('products')->delete();
        DB::table('car_generations')->delete();
        DB::table('car_models')->delete();
        DB::table('car_brands')->delete();
    }

    private function report(array $stats, int $skipped): void
    {
        $products = Product::count();
        $multiOffer = DB::table('offers')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $this->table(['Показатель', 'Значение'], [
            ['Марок',                     CarBrand::count()],
            ['Моделей',                   CarModel::count()],
            ['Карточек товара',           $products],
            ['Предложений (офферов)',     Offer::count()],
            ['Записей применимости',      Fitment::count()],
            ['Карточек с >1 предложением', $multiOffer],
            ['Опубликовано',              Product::where('is_published', true)->count()],
            ['На ручную проверку',        Product::where('needs_review', true)->count()],
            ['Строк пропущено (нет марки или типа)', $skipped],
        ]);

        $this->newLine();
        $this->line('Топ карточек с несколькими предложениями:');

        // has() вместо having(): SQLite не принимает HAVING по подзапросу без GROUP BY
        $top = Product::withCount('offers')->has('offers', '>', 1)
            ->orderByDesc('offers_count')->limit(5)->get();

        foreach ($top as $p) {
            $this->line(sprintf(
                '  %d предл.  %s  %s ₸',
                $p->offers_count,
                Str::limit($p->name, 65),
                $p->price_min ? number_format((float) $p->price_min, 0, ',', ' ').'–'.number_format((float) $p->price_max, 0, ',', ' ') : '—'
            ));
        }
    }
}
