<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Раз в час: снять резервы с заказов, которые так и не оплатили.
 * Чаще незачем — срок брони считается в днях; реже опасно, потому что
 * каждая висящая бронь показывает витрине товар как отсутствующий.
 */
Schedule::command('stock:release-expired')->hourly();
Schedule::command('sitemap:generate')->daily();

Schedule::command('ordering:prune-idempotency')->hourly()
    ->when(fn () => config('ordering.api_enabled'))->withoutOverlapping(10);
