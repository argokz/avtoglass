<?php

namespace App\Services\Scraper;

use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Скрапер и сборщик медиафайлов из интернета для всех элементов каталога.
 */
class InternetMediaScraper
{
    /** Надежные открытые репозитории и CDN логотипов автомобилей */
    private const BRAND_LOGO_SOURCES = [
        'https://raw.githubusercontent.com/filippofilip95/car-logos-dataset/master/logos/optimized/%s.svg',
        'https://raw.githubusercontent.com/nvg/car-logos/master/%s.svg',
        'https://www.carlogos.org/car-logos/%s-logo.png',
    ];

    /** Официальные логотипы поставщиков / заводов автостекол */
    private const SUPPLIER_LOGOS = [
        'fuyao' => 'https://upload.wikimedia.org/wikipedia/commons/4/4e/Fuyao_Group_logo.svg',
        'agc' => 'https://upload.wikimedia.org/wikipedia/commons/f/f6/AGC_Inc._logo.svg',
        'xyg' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c5/Xinyi_Glass_logo.svg/320px-Xinyi_Glass_logo.svg.png',
        'pilkington' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/ca/Pilkington_logo.svg/320px-Pilkington_logo.svg.png',
        'sekurit' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/4f/Saint-Gobain_Sekurit_logo.svg/320px-Saint-Gobain_Sekurit_logo.svg.png',
        'benson' => 'https://raw.githubusercontent.com/filippofilip95/car-logos-dataset/master/logos/optimized/benson.svg',
        'nordglass' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/9c/NordGlass_logo.png/320px-NordGlass_logo.png',
        'kmk' => 'https://kmk.ru/img/logo.png',
    ];

    /**
     * 1. Скачивание логотипов всех марок автомобилей.
     */
    public function scrapeBrandLogos(bool $force = false): int
    {
        $brands = CarBrand::all();
        $count = 0;

        foreach ($brands as $brand) {
            if (filled($brand->logo_path) && ! $force && Storage::disk('public')->exists($brand->logo_path)) {
                continue;
            }

            $slug = Str::slug($brand->name);
            $content = $this->downloadBrandLogo($brand->name, $slug);

            if ($content !== null) {
                /*
                 * Расширение — по началу файла, а не по вхождению «<svg»
                 * где угодно: у любой HTML-страницы ошибки внутри есть иконки
                 * в <svg>, и так страница 404 сохранялась как brands/vaz.svg.
                 */
                $ext = $this->imageKind($content) ?? 'svg';
                $path = "brands/{$slug}.{$ext}";

                Storage::disk('public')->put($path, $content);
                $brand->update(['logo_path' => $path]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 2. Загрузка логотипов производителей автостекол.
     */
    public function scrapeSupplierLogos(bool $force = false): int
    {
        $suppliers = Supplier::all();
        $count = 0;

        foreach ($suppliers as $supplier) {
            if (filled($supplier->logo_path) && ! $force && Storage::disk('public')->exists($supplier->logo_path)) {
                continue;
            }

            $normalized = mb_strtolower(trim($supplier->name));
            $slug = Str::slug($supplier->name);
            $content = null;
            $ext = 'svg';

            // Сопоставление с онлайн-источниками
            $matchKey = match (true) {
                str_contains($normalized, 'fuyao') => 'fuyao',
                str_contains($normalized, 'agc') => 'agc',
                str_contains($normalized, 'xyg') => 'xyg',
                str_contains($normalized, 'pilkington') => 'pilkington',
                str_contains($normalized, 'benson') => 'benson',
                str_contains($normalized, 'nordglass') => 'nordglass',
                /*
                 * У BOR и BGC (Бор, Россия) своего логотипа в открытых
                 * источниках нет. Раньше им подставлялся знак AGC — японского
                 * завода, — и покупатель видел чужую марку на российском
                 * стекле. Лучше нарисованная заглушка с настоящим названием.
                 */
                str_contains($normalized, 'kmk') || str_contains($normalized, 'кмк') => 'kmk',
                default => null,
            };

            if ($matchKey && isset(self::SUPPLIER_LOGOS[$matchKey])) {
                try {
                    $res = Http::withHeaders(['User-Agent' => 'AvtoGlassKZ/1.0'])->timeout(4)->get(self::SUPPLIER_LOGOS[$matchKey]);
                    $kind = $res->successful() ? $this->imageKind($res->body()) : null;

                    if ($kind !== null) {
                        $content = $res->body();
                        $ext = $kind;
                    }
                } catch (\Throwable) {
                }
            }

            // Качественный векторный fallback логотип
            if ($content === null) {
                $content = $this->generateSupplierSvg($supplier->name, $supplier->tier);
                $ext = 'svg';
            }

            $path = "suppliers/{$slug}.{$ext}";
            Storage::disk('public')->put($path, $content);
            $supplier->update(['logo_path' => $path]);
            $count++;
        }

        return $count;
    }

    /**
     * Снимки моделей, которые покупатель видит в каталоге.
     *
     * Раньше Wikipedia вызывалась только у «популярных» и у тех, у кого
     * больше пяти стёкол, а остальным записывался один и тот же SVG-силуэт.
     * На витрине это выглядело как «фото есть», хотя Camry и Matiz были
     * одной картинкой. Теперь качаем растр для каждой модели с товарами
     * и силуэт в файл не кладём: его рисует шаблон, когда снимка нет.
     */
    public function scrapeModelPhotos(bool $force = false, int $limit = 0): int
    {
        $query = CarModel::query()
            ->with('brand')
            ->where('products_count', '>', 0)
            ->orderByDesc('products_count')
            ->orderBy('id');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('image_path')
                    ->orWhere('image_path', 'like', '%.svg');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $bar = null;
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $total = (clone $query)->count();
            if ($limit > 0) {
                $total = min($total, $limit);
            }
            if ($total > 0) {
                $bar = new ProgressBar(new ConsoleOutput, $total);
                $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
                $bar->setMessage('поиск фото');
                $bar->start();
            }
        }

        $count = 0;

        foreach ($query->cursor() as $model) {
            if (! $force && $this->hasUsablePhoto($model)) {
                $bar?->setMessage($model->name.' уже есть');
                $bar?->advance();

                continue;
            }

            $brandName = $model->brand->name ?? '';
            $binary = $this->fetchCarPhoto($brandName, $model->name);
            $ext = $binary !== null ? $this->rasterExtension($binary) : null;

            if ($binary === null || $ext === null) {
                if (filled($model->image_path) && ! $model->hasPhoto()) {
                    $model->update(['image_path' => null]);
                }

                $bar?->setMessage($model->name.' нет снимка');
                $bar?->advance();
                $this->pauseBetweenModels();

                continue;
            }

            $brandSlug = Str::slug($brandName !== '' ? $brandName : 'brand');
            $modelSlug = Str::slug($model->name);
            $path = "models/{$brandSlug}-{$modelSlug}.{$ext}";

            Storage::disk('public')->put($path, $binary);
            $model->update(['image_path' => $path]);
            $count++;

            $bar?->setMessage($model->name.' фото');
            $bar?->advance();
            $this->pauseBetweenModels();
        }

        $bar?->finish();

        return $count;
    }

    /**
     * 4. Наполнение автостёкол чертежами с размерами (Ширина x Высота).
     */
    public function scrapeGlassMedia(bool $force = false, int $limit = 0): array
    {
        $query = Product::query();
        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('image_path')
                    ->orWhereNull('width_mm')
                    ->orWhereNull('height_mm');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $scraper = new AutoGlassScraper;
        $stats = ['images' => 0, 'dimensions' => 0];

        $query->chunkById(250, function ($products) use ($scraper, $force, &$stats) {
            foreach ($products as $product) {
                $data = $scraper->fetchDataForProduct($product);

                if ($data !== null) {
                    if ($force) {
                        $product->image_path = null;
                    }

                    $updated = $scraper->applyDataToProduct($product, $data);
                    if ($updated['image']) {
                        $stats['images']++;
                    }
                    if ($updated['dimensions']) {
                        $stats['dimensions']++;
                    }
                }
            }
        });

        return $stats;
    }

    private function downloadBrandLogo(string $name, string $slug): ?string
    {
        foreach (self::BRAND_LOGO_SOURCES as $pattern) {
            $url = sprintf($pattern, $slug);
            try {
                $res = Http::withHeaders(['User-Agent' => 'AvtoGlassKZ/1.0'])->timeout(3)->get($url);

                // Код 200 ещё не значит, что пришла картинка: многие источники
                // на отсутствующий файл отвечают обычной HTML-страницей.
                if ($res->successful() && $this->imageKind($res->body()) !== null) {
                    return $res->body();
                }
            } catch (\Throwable) {
            }
        }

        return $this->generateBrandSvg($name);
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'AvtoGlassKZ/1.0 (https://avto-glass.kz; catalog photos; order@avto-glass.kz)',
            'Accept-Language' => 'en, ru;q=0.9',
        ])->timeout(8)->connectTimeout(5);
    }

    private function hasUsablePhoto(CarModel $model): bool
    {
        return $model->hasPhoto()
            && Storage::disk('public')->exists($model->image_path);
    }

    private function fetchCarPhoto(string $brand, string $model): ?string
    {
        $queries = $this->searchQueries($brand, $model);

        foreach ($queries as $query) {
            $binary = $this->fetchWikipediaImage('en', $query, $brand, $model)
                ?? $this->fetchWikipediaImage('ru', $query, $brand, $model);

            if ($binary !== null) {
                return $binary;
            }
        }

        foreach ($queries as $query) {
            $binary = $this->fetchCommonsImage($query, $brand, $model);
            if ($binary !== null) {
                return $binary;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function searchQueries(string $brand, string $model): array
    {
        $brand = $this->searchBrandName($brand);
        $queries = [];

        foreach ($this->modelSearchVariants($model) as $variant) {
            $queries[] = trim($brand.' '.$variant);
            if (mb_strlen($variant) <= 3) {
                $queries[] = trim($brand.' '.$variant.' automobile');
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * Как модель записана в прайсе и как её ищут в Википедии — часто разные
     * строки: HI-LUX, W221, S-Class, Landcruiser FJ200/LEXUS.
     *
     * @return list<string>
     */
    private function modelSearchVariants(string $model): array
    {
        $core = $this->coreModelName($model);
        if ($core === '') {
            $core = trim($model);
        }

        $variants = [$core];

        $aliased = $this->aliasModelName($core);
        if ($aliased !== $core) {
            $variants[] = $aliased;
        }

        if (preg_match('/^W\d{3}[A-Z]?,\s*(.+)$/iu', $core, $match)) {
            $variants[] = trim($match[1]);
        }

        if (preg_match('/^(.+?)\s+W$/iu', $core, $match)) {
            $variants[] = trim($match[1]);
        }

        $short = $this->primaryModelToken($core);
        if ($short !== $core && $short !== '') {
            $variants[] = $short;
            $aliasedShort = $this->aliasModelName($short);
            if ($aliasedShort !== $short) {
                $variants[] = $aliasedShort;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function aliasModelName(string $name): string
    {
        $key = mb_strtolower(str_replace('ё', 'е', trim($name)));

        return match ($key) {
            'hi-lux', 'hilux' => 'Hilux',
            'geleandewagen', 'gelandewagen' => 'G-Class',
            'landcr', 'landcruiser' => 'Land Cruiser',
            'range rover voque', 'voque' => 'Range Rover Vogue',
            'outlan', 'outlander xl' => 'Outlander',
            'sonata nf', 'sonata vii lf' => 'Sonata',
            'espace grand' => 'Espace',
            'tiggo 8-pro' => 'Tiggo 8',
            'prius iii usa' => 'Prius',
            'proceed lhd' => 'ProCeed',
            'c-class w' => 'C-Class',
            's-class w' => 'S-Class',
            'газон next' => 'GAZon Next',
            '2190' => 'Granta',
            '2191' => 'Granta',
            '099' => '2109',
            'laura' => 'Octavia',
            'bonus' => 'A13',
            'fora' => 'Fora',
            default => $name,
        };
    }

    private function searchBrandName(string $brand): string
    {
        return match (mb_strtolower(trim($brand))) {
            'ваз', 'vaz' => 'Lada',
            'газ' => 'GAZ',
            'уаз' => 'UAZ',
            'заз' => 'ZAZ',
            default => $brand,
        };
    }

    private function coreModelName(string $model): string
    {
        $clean = preg_replace('/\([^)]*\)/u', ' ', $model) ?? $model;
        $clean = preg_replace('#\s*/\s*[A-Za-zА-Яа-яЁё]{2,}.*$#u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:19|20)\d{2}(?:\s*[–\-]\s*(?:19|20)?\d{2})?\b/u', ' ', $clean) ?? $clean;
        $clean = str_ireplace('Landcruiser', 'Land Cruiser', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    /** «Land Cruiser 200» и «Camry XV70» на Википедии живут без хвоста поколения. */
    private function primaryModelToken(string $core): string
    {
        $short = trim(preg_replace('/\s+\(?[A-Za-z]{0,3}\d{2,3}\)?$/u', '', $core) ?? $core);
        $short = trim(preg_replace('/\s+\d{2,4}[a-zA-Z]?$/u', '', $short) ?? $short);

        return $short !== '' ? $short : $core;
    }

    private function fetchWikipediaImage(string $lang, string $query, string $brand, string $model): ?string
    {
        try {
            $res = $this->client()->get("https://{$lang}.wikipedia.org/w/api.php", [
                'action' => 'query',
                'generator' => 'search',
                'gsrsearch' => $query,
                'gsrlimit' => 5,
                'prop' => 'pageimages',
                'pithumbsize' => 800,
                'piprop' => 'thumbnail',
                'format' => 'json',
                'formatversion' => 2,
                'redirects' => 1,
            ]);

            if (! $res->successful()) {
                return null;
            }

            foreach ($this->queryPages($res->json() ?? []) as $page) {
                $title = (string) ($page['title'] ?? '');
                $thumb = $page['thumbnail']['source'] ?? null;

                if (! is_string($thumb) || $thumb === '' || ! $this->titleLooksLikeCar($title, $brand, $model)) {
                    continue;
                }

                $binary = $this->downloadRasterImage($thumb);
                if ($binary !== null) {
                    return $binary;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function fetchCommonsImage(string $query, string $brand, string $model): ?string
    {
        try {
            $res = $this->client()->get('https://commons.wikimedia.org/w/api.php', [
                'action' => 'query',
                'generator' => 'search',
                'gsrsearch' => $query,
                'gsrnamespace' => 6,
                'gsrlimit' => 8,
                'prop' => 'imageinfo',
                'iiprop' => 'url|mime|size',
                'iiurlwidth' => 800,
                'format' => 'json',
                'formatversion' => 2,
            ]);

            if (! $res->successful()) {
                return null;
            }

            $best = null;
            $bestScore = 0;

            foreach ($this->queryPages($res->json() ?? []) as $page) {
                $title = (string) ($page['title'] ?? '');
                $info = $page['imageinfo'][0] ?? [];
                $mime = (string) ($info['mime'] ?? '');
                $url = $info['thumburl'] ?? $info['url'] ?? null;

                if (! is_string($url) || $url === '' || ! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
                    continue;
                }

                $score = $this->scoreCommonsTitle($title, $brand, $model);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $url;
                }
            }

            if ($best === null || $bestScore < 8) {
                return null;
            }

            return $this->downloadRasterImage($best);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $json */
    private function queryPages(array $json): array
    {
        $pages = $json['query']['pages'] ?? [];

        return is_array($pages) ? array_values($pages) : [];
    }

    private function titleLooksLikeCar(string $title, string $brand, string $model): bool
    {
        $title = mb_strtolower($title);

        foreach (['disambiguation', 'album', 'song', 'film', 'football', 'stadium', 'ship', 'hurricane'] as $reject) {
            if (str_contains($title, $reject)) {
                return false;
            }
        }

        $brand = mb_strtolower($this->searchBrandName($brand));
        $needles = [];
        foreach ($this->modelSearchVariants($model) as $variant) {
            $needles[] = mb_strtolower($variant);
        }

        $hasModel = false;
        foreach (array_unique(array_filter($needles)) as $needle) {
            if (mb_strlen($needle) >= 2 && str_contains($title, $needle)) {
                $hasModel = true;
                break;
            }
        }

        if (! $hasModel) {
            return false;
        }

        $longest = '';
        foreach ($needles as $needle) {
            if (mb_strlen($needle) > mb_strlen($longest)) {
                $longest = $needle;
            }
        }

        // Короткие имена вроде Rio и X5 без марки цепляют чужие статьи.
        return $brand === '' || str_contains($title, $brand) || mb_strlen($longest) >= 6;
    }

    private function scoreCommonsTitle(string $title, string $brand, string $model): int
    {
        $title = mb_strtolower($title);
        $score = 0;
        $brand = mb_strtolower($this->searchBrandName($brand));
        $core = mb_strtolower($this->coreModelName($model));

        $short = mb_strtolower($this->primaryModelToken($core));

        if ($core !== '' && str_contains($title, $core)) {
            $score += 10;
        } elseif ($short !== '' && $short !== $core && str_contains($title, $short)) {
            $score += 8;
        }
        if ($brand !== '' && str_contains($title, $brand)) {
            $score += 6;
        }

        foreach (['front', 'exterior', 'sedan', 'suv'] as $hint) {
            if (str_contains($title, $hint)) {
                $score += 2;
            }
        }

        foreach (['logo', 'emblem', 'badge', 'wordmark', 'icon', '.svg'] as $reject) {
            if (str_contains($title, $reject)) {
                $score -= 30;
            }
        }

        foreach (['interior', 'engine', 'dashboard', 'steering'] as $reject) {
            if (str_contains($title, $reject)) {
                $score -= 12;
            }
        }

        return $score;
    }

    private function downloadRasterImage(string $url): ?string
    {
        try {
            $res = $this->client()->timeout(12)->get($url);
            if (! $res->successful() || strlen($res->body()) < 2500) {
                return null;
            }

            return $this->rasterExtension($res->body()) !== null ? $res->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Что за картинка пришла — или null, если это вообще не картинка.
     *
     * SVG признаём только по началу файла. Проверка «есть ли где-то <svg»
     * пропускает HTML: на любой странице ошибки иконки нарисованы в <svg>.
     */
    private function imageKind(string $binary): ?string
    {
        $head = ltrim(substr($binary, 0, 512), "\xEF\xBB\xBF \t\r\n");

        if (stripos($head, '<!doctype html') === 0 || stripos($head, '<html') === 0) {
            return null;
        }

        if (str_starts_with($head, '<svg') || (str_starts_with($head, '<?xml') && str_contains($head, '<svg'))) {
            return 'svg';
        }

        return $this->rasterExtension($binary);
    }

    private function rasterExtension(string $binary): ?string
    {
        $head = ltrim(substr($binary, 0, 256));

        if (str_starts_with($head, '<svg') || (str_starts_with($head, '<?xml') && str_contains($head, '<svg'))) {
            return null;
        }

        return match (true) {
            str_starts_with($binary, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($binary, "\x89PNG") => 'png',
            str_starts_with($binary, 'GIF8') => 'gif',
            str_starts_with($binary, 'RIFF') && str_contains(substr($binary, 0, 16), 'WEBP') => 'webp',
            default => null,
        };
    }

    private function pauseBetweenModels(): void
    {
        if (! app()->runningUnitTests()) {
            usleep(80_000);
        }
    }

    private function generateBrandSvg(string $name): string
    {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
    <rect width="100" height="100" rx="16" fill="#0f172a" />
    <circle cx="50" cy="50" r="38" fill="none" stroke="#38bdf8" stroke-width="3.5" />
    <text x="50" y="56" fill="#ffffff" font-family="sans-serif" font-weight="900" font-size="14" text-anchor="middle">{$name}</text>
</svg>
SVG;
    }

    private function generateSupplierSvg(string $name, ?string $tier): string
    {
        $color = match ($tier) {
            'oem' => '#10b981',
            'premium' => '#38bdf8',
            'economy' => '#f59e0b',
            default => '#94a3b8',
        };

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 70" width="200" height="70">
    <rect width="200" height="70" rx="12" fill="#0f172a" stroke="{$color}" stroke-width="1.5" />
    <text x="100" y="42" fill="#ffffff" font-family="sans-serif" font-weight="bold" font-size="16" text-anchor="middle">{$name}</text>
</svg>
SVG;
    }
}
