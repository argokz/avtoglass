<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\CustomerCabinetController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderLookupController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StorefrontModeController;
use App\Http\Controllers\WholesaleController;
use App\Http\Middleware\OnlyCustomers;
use App\Http\Middleware\OnlyWholesale;
use App\Models\Product;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/ordering.php';

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/preview/ux', function () {
    abort_unless(app()->environment('preview'), 404);

    return response()->view('preview.ux')->header('Cache-Control', 'no-store');
})->name('preview.ux');
Route::post('/interfeis', StorefrontModeController::class)->name('storefront.mode');
Route::get('/chat', function () {
    abort_unless(settings()->aiChatEnabled(), 404);

    return view('home-chat');
})->name('assistant');
Route::get('/search', [HomeController::class, 'search'])->name('search');
Route::post('/pick', [HomeController::class, 'pick'])->name('pick');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', function () {
    return response(file_get_contents(public_path('robots.txt')), 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
})->name('robots');
Route::get('/privacy', PrivacyController::class)->name('privacy');

Route::post('/gorod', function (Request $request) {
    $city = trim((string) $request->input('city'));

    if (in_array($city, settings()->cities(), true)) {
        session(['city' => $city]);
    }

    return back();
})->name('city.select');

// для зависимого селекта «марка -> модель» на главной
Route::get('/api/brands/{brand:slug}/models', [HomeController::class, 'models'])->name('api.models');

// Заявки. Ограничение по частоте — чтобы форму не залили ботами.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/tovar/{product:slug}/zayavka', [OrderController::class, 'store'])->name('order.store');
    Route::post('/podbor', [OrderController::class, 'selection'])->name('order.selection');
});

// Корзина. Живёт в сессии, поэтому авторизация не нужна.
Route::get('/korzina', [CartController::class, 'show'])->name('cart.show');

// Оформление объявлено ДО маршрута со слагом: иначе «oformit» будет принят
// за слаг товара, и Laravel вернёт 404 на попытку оформить заказ.
Route::post('/korzina/oformit', [CartController::class, 'checkout'])
    ->middleware('throttle:10,1')
    ->name('cart.checkout');

Route::post('/korzina/{product:slug}', [CartController::class, 'add'])->name('cart.add');
Route::patch('/korzina/{product:slug}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/korzina/{product:slug}', [CartController::class, 'remove'])->name('cart.remove');

Route::get('/podbor', [OrderController::class, 'form'])->name('order.form');
Route::get('/spasibo', [OrderController::class, 'thanks'])->name('order.thanks');
Route::get('/zakaz/{order:public_token}', [OrderController::class, 'track'])->name('order.track');

Route::get('/status', [OrderLookupController::class, 'form'])->name('orders.lookup');
Route::post('/status', [OrderLookupController::class, 'lookup'])
    ->middleware('throttle:5,1')
    ->name('orders.find');
Route::get('/moi-zayavki', [OrderLookupController::class, 'mine'])->name('orders.mine');
Route::post('/moi-zayavki/vyhod', [OrderLookupController::class, 'logout'])->name('orders.logout');

/*
 * Кабинет покупателя. Заводить его необязательно: заявка оформляется без
 * регистрации, и вход по телефону с номером заявки выше тоже никуда не делся.
 * Аккаунт нужен тем, кто возвращается — чтобы не искать номер каждый раз.
 *
 * Попытки входа ограничены по частоте: телефон угадывается перебором заметно
 * легче почты, тут всего одиннадцать цифр с известным началом.
 */
Route::middleware('guest')->group(function () {
    Route::get('/registraciya', [CustomerAccountController::class, 'registerForm'])->name('account.register');
    Route::post('/registraciya', [CustomerAccountController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('account.register.store');

    Route::get('/vhod', [CustomerAccountController::class, 'loginForm'])->name('account.login');
    Route::post('/vhod', [CustomerAccountController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('account.login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/kabinet/vyhod', [CustomerAccountController::class, 'logout'])->name('account.logout');
});

Route::middleware(['auth', OnlyCustomers::class])->group(function () {
    Route::get('/kabinet', [CustomerCabinetController::class, 'orders'])->name('account.orders');
    Route::get('/kabinet/profil', [CustomerCabinetController::class, 'profile'])->name('account.profile');
    Route::post('/kabinet/profil', [CustomerCabinetController::class, 'updateProfile'])
        ->middleware('throttle:10,1')
        ->name('account.profile.update');
    Route::get('/kabinet/privyazat', [CustomerCabinetController::class, 'claimForm'])->name('account.claim');
    Route::post('/kabinet/privyazat', [CustomerCabinetController::class, 'claim'])
        ->middleware('throttle:5,1')
        ->name('account.claim.store');
});

/*
 * Опт. Витрина — всем вошедшим клиентам (внутри понятные экраны для
 * розницы и «на проверке»), приём спецификации — только одобренным.
 */
Route::middleware(['auth', OnlyCustomers::class])->group(function () {
    Route::get('/opt', [WholesaleController::class, 'index'])->name('wholesale.index');
});

Route::middleware(['auth', OnlyCustomers::class, OnlyWholesale::class])->group(function () {
    Route::post('/opt/zayavka', [WholesaleController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('wholesale.store');
});

// Оплата. Колбэк без CSRF: его шлёт касса, а не браузер клиента, —
// защищает подпись, а не сессия.
Route::post('/oplata', [PaymentController::class, 'start'])->name('payment.start');
Route::get('/oplata/kassa/{payment}', [PaymentController::class, 'mock'])->name('payment.mock');
Route::get('/oplata/callback', [PaymentController::class, 'callback'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('payment.callback');
Route::get('/oplata/itog/{payment}', [PaymentController::class, 'result'])->name('payment.result');

// Halyk ePay поднимает форму карты виджетом на нашей странице, а не редиректом
// на домен банка, поэтому здесь свой маршрут и свой приёмник уведомлений.
Route::get('/oplata/halyk/{payment}', [PaymentController::class, 'halyk'])->name('payment.halyk');
Route::post('/oplata/halyk/callback', [PaymentController::class, 'halykCallback'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('payment.halyk.callback');

// Freedom Pay ждёт в ответ подписанный XML: без него он считает, что магазин
// уведомление не принял, и присылает его снова.
Route::post('/oplata/freedompay/callback', [PaymentController::class, 'freedomPayCallback'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('payment.freedompay.callback');

// Печатные документы. Только для сотрудников: внутри телефон клиента,
// адрес и суммы — по угадываемой ссылке такое отдавать нельзя.
Route::middleware('auth')->group(function () {
    Route::get('/dokument/zakaz/{order}/{type}', [DocumentController::class, 'order'])->name('document.order');
    Route::get('/dokument/reestr/{courier}', [DocumentController::class, 'manifest'])->name('document.manifest');
});

// Подбор по VIN. Отдельным адресом, а не вкладкой каталога: на него ссылаются
// менеджеры в переписке с клиентом («пришлите VIN сюда»).
Route::get('/vin', function () {
    abort_unless(settings()->enabled('vin_lookup'), 404);

    return view('vin.index');
})->name('vin.form');

/*
 * Запись на замену. Товар необязателен: записываются и «просто на замену»,
 * когда стекло уже куплено или его подберёт мастер на месте.
 */
Route::get('/zapis/{product:slug?}', function (?Product $product = null) {
    abort_unless(settings()->enabled('booking'), 404);

    return view('booking.index', [
        'product' => $product?->is_published ? $product : null,
    ]);
})->name('booking.form');

Route::get('/catalog', [CatalogController::class, 'brands'])->name('catalog.brands');
Route::get('/tovar/{product:slug}', [CatalogController::class, 'product'])->name('catalog.product');

/*
 * Расходники без привязки к машине. Отдельный маршрут, а не раздел каталога:
 * дерево каталога начинается с марки, а у молдинга её нет.
 */
Route::get('/rashodniki', [CatalogController::class, 'consumables'])->name('catalog.consumables');

// Слаг модели уникален только внутри марки, поэтому связывание — scoped:
// иначе /catalog/bmw/camry отдал бы тойотовскую Camry.
Route::get('/catalog/{brand:slug}', [CatalogController::class, 'models'])->name('catalog.models');
Route::get('/catalog/{brand:slug}/{model:slug}', [CatalogController::class, 'products'])
    ->scopeBindings()
    ->name('catalog.products');
