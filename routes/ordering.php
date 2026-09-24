<?php

use App\Http\Controllers\Ordering\DraftController;
use App\Http\Controllers\Ordering\OfferController;
use App\Http\Middleware\OrderingBuyer;
use Illuminate\Support\Facades\Route;

// Included from web.php: session cookies and PreventRequestForgery remain active.
Route::prefix('api/v1')->name('ordering.')->middleware([OrderingBuyer::class, 'throttle:60,1,ordering-read'])->group(function () {
    // Session blocking also protects first-time guest-token creation in concurrent tabs.
    Route::get('session', [DraftController::class, 'session'])->block(30, 10)->name('session');
    Route::get('offers', [OfferController::class, 'index'])->block(30, 10)->name('offers.index');
    Route::get('drafts/{draft}', [DraftController::class, 'get'])->block(30, 10)->name('drafts.get');
    Route::get('drafts/{draft}/quotes/{quote}', [DraftController::class, 'getQuote'])->block(30, 10)->name('quotes.get');
    Route::middleware('throttle:20,1,ordering-write')->group(function () {
        Route::post('drafts', [DraftController::class, 'create'])->block(30, 10)->name('drafts.create');
        Route::patch('drafts/{draft}', [DraftController::class, 'update'])->block(30, 10)->name('drafts.update');
        Route::post('drafts/{draft}/quotes', [DraftController::class, 'quote'])->block(30, 10)->name('quotes.create');
        Route::post('drafts/{draft}/submit', [DraftController::class, 'submit'])->block(30, 10)->name('drafts.submit');
    });
});
