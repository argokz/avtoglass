<?php

use App\Models\Customer;
use App\Models\Offer;
use App\Models\Product;
use App\Settings\AppSettings;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;

/** Display legacy decimal prices without discarding non-zero tiyn. */
function formatPrice(float|int|string $amount): string
{
    $decimal = number_format((float) $amount, 2, '.', '');
    $precision = str_ends_with($decimal, '.00') ? 0 : 2;

    return number_format((float) $decimal, $precision, ',', ' ');
}

if (! function_exists('файлВитрины')) {
    /**
     * Адрес файла из public/storage с учётом подпути витрины.
     *
     * Storage::url() берёт APP_URL (http://localhost:8014) и не знает
     * про X-Forwarded-Prefix. С https://itwin.kz/avto-glass браузер
     * покупателя ходит на его собственный localhost:8014 — соединение
     * отклоняется, логотипы марок не грузятся.
     */
    function файлВитрины(string $path): string
    {
        return asset('storage/'.$path);
    }
}

if (! function_exists('шрифты')) {
    /**
     * @fonts из Vite вставляет CSS с url("/build/assets/...").
     * Прелоады шрифтов идут через asset() и префикс получают, а url()
     * внутри <style> — нет: этот CSS собрали заранее. На /avto-glass
     * браузер просит шрифт с корня itwin.kz, там ITwin, ответ 404.
     */
    function шрифты(): HtmlString
    {
        $html = (string) Vite::fonts();
        $build = rtrim((string) parse_url(url('/build'), PHP_URL_PATH), '/');

        if ($build !== '' && $build !== '/build') {
            $html = str_replace(
                ['url("/build/', "url('/build/"],
                ['url("'.$build.'/', "url('".$build.'/'],
                $html,
            );
        }

        return new HtmlString($html);
    }
}

if (! function_exists('settings')) {
    /** Настройки из CRM: то, что админ включает и выключает без выкладки кода. */
    function settings(): AppSettings
    {
        return app(AppSettings::class);
    }
}

if (! function_exists('currentCity')) {
    /** Город витрины: из сессии, иначе город по умолчанию из настроек. */
    function currentCity(): string
    {
        $cities = settings()->cities();
        $city = session('city');

        if (is_string($city) && in_array($city, $cities, true)) {
            return $city;
        }

        $default = settings()->defaultCity();

        return in_array($default, $cities, true) ? $default : ($cities[0] ?? $default);
    }
}

if (! function_exists('склонение')) {
    /**
     * Русское склонение существительного при числе: 1 модель, 2 модели,
     * 5 моделей. Само число не подставляет — только слово.
     *
     * Без этого в каталоге девять марок подписаны «1 моделей»: витрина
     * с такой подписью выглядит недоделанной, и это первое, за что
     * цепляется глаз покупателя.
     */
    function склонение(int $number, string $one, string $few, string $many): string
    {
        $n = abs($number) % 100;

        if ($n >= 11 && $n <= 14) {
            return $many;
        }

        return match ($n % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }
}

if (! function_exists('телефонДляГлаз')) {
    /**
     * Казахстанский номер в читаемом виде: 77003375208 → +7 (700) 337-52-08.
     *
     * В базе телефон лежит цифрами — так его сравнивают и ищут. Показывать
     * человеку эту же строку незачем: свой номер он узнаёт по привычной
     * разбивке, а не по одиннадцати слитным цифрам.
     */
    function телефонДляГлаз(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (preg_match('/^[78](\d{3})(\d{3})(\d{2})(\d{2})$/', $digits, $m)) {
            return "+7 ({$m[1]}) {$m[2]}-{$m[3]}-{$m[4]}";
        }

        // Незнакомый формат отдаём как есть: выдумывать разбивку хуже, чем не разбивать.
        return (string) $phone;
    }
}

if (! function_exists('текущийПокупатель')) {
    /** Карточка вошедшего клиента. Сотрудник, открывший витрину, покупателем не считается. */
    function текущийПокупатель(): ?Customer
    {
        $user = auth()->user();

        if ($user === null || $user->isStaff() || $user->customer_id === null) {
            return null;
        }

        return $user->loadMissing('customer')->customer;
    }
}

if (! function_exists('ценаДляПокупателя')) {
    function ценаДляПокупателя(Product $product, ?Offer $offer = null): ?float
    {
        $buyer = текущийПокупатель();

        if ($offer !== null) {
            return $offer->sellingPrice($buyer);
        }

        return $product->sellingPriceMin($buyer);
    }
}
