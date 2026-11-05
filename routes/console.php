<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Раз в час: снять резервы с заказов, которые так и не оплатили.
 * Чаще незачем — срок брони считается в днях; реже опасно, потому что
 * каждая висящая бронь показывает витрине товар как отсутствующий.
 */
Schedule::command('stock:release-expired')->everyMinute()->withoutOverlapping(5);
Schedule::command('assistant:prune')->daily()->withoutOverlapping(10);
Schedule::command('sitemap:generate')->daily();

// Срок хранения ПДн: контакты по давно закрытым заявкам заменяются срезом.
Schedule::command('pdn:anonymize')->daily()->withoutOverlapping(10);

Schedule::command('ordering:prune-idempotency')->hourly()
    ->withoutOverlapping(10);

// Регулярные заявки оптовиков: днём раз в час, чтобы сбой связи повторился, а не потерял напоминание.
Schedule::command('wholesale:remind')->hourlyAt(5)->between('9:00', '19:00')
    ->withoutOverlapping(10);

// Chat submissions use the outbox even when the public ordering API is disabled.
Schedule::command('ordering:process-outbox')->everyMinute()
    ->when(fn () => Schema::hasTable('outbox_events'))
    ->withoutOverlapping(10);
