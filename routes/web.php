<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Models\Product;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/search', [HomeController::class, 'search'])->name('search');
Route::post('/pick', [HomeController::class, 'pick'])->name('pick');

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
Route::view('/vin', 'vin.index')->name('vin.form');

/*
 * Запись на замену. Товар необязателен: записываются и «просто на замену»,
 * когда стекло уже куплено или его подберёт мастер на месте.
 */
Route::get('/zapis/{product:slug?}', fn (?Product $product = null) => view('booking.index', [
    'product' => $product?->is_published ? $product : null,
]))->name('booking.form');

Route::get('/catalog', [CatalogController::class, 'brands'])->name('catalog.brands');
Route::get('/tovar/{product:slug}', [CatalogController::class, 'product'])->name('catalog.product');

// Слаг модели уникален только внутри марки, поэтому связывание — scoped:
// иначе /catalog/bmw/camry отдал бы тойотовскую Camry.
Route::get('/catalog/{brand:slug}', [CatalogController::class, 'models'])->name('catalog.models');
Route::get('/catalog/{brand:slug}/{model:slug}', [CatalogController::class, 'products'])
    ->scopeBindings()
    ->name('catalog.products');
