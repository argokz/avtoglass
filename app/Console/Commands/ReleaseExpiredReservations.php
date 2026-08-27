<?php

namespace App\Console\Commands;

use App\Catalog\StockLedger;
use Illuminate\Console\Command;

/**
 * Снимает брони по заказам, которые так и не оплатили.
 *
 * Без этой команды склад тихо «заканчивается»: заявок, доходящих до оплаты,
 * примерно половина, а бронь висит на каждой подтверждённой. Через неделю
 * витрина показывает «нет в наличии» при полных полках.
 */
class ReleaseExpiredReservations extends Command
{
    protected $signature = 'stock:release-expired';

    protected $description = 'Снять просроченные резервы по неоплаченным заказам';

    public function handle(StockLedger $ledger): int
    {
        $released = $ledger->releaseExpired();

        $this->info($released > 0
            ? "Снято резервов: {$released}"
            : 'Просроченных резервов нет');

        return self::SUCCESS;
    }
}
