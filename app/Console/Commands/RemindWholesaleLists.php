<?php

namespace App\Console\Commands;

use App\Models\WholesaleList;
use App\Notifications\WhatsAppClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Напоминание о регулярной заявке: «сегодня понедельник — закупка».
 *
 * Пишем только тем, кто сам поставил день у списка, и одним сообщением
 * на клиента, сколько бы списков ни выпало на день. Заказ не создаём:
 * в сообщении ссылка, по которой список уже отмечен на /opt, а отправляет
 * оптовик сам — цены и наличие могли измениться с прошлой недели.
 *
 * Запускается ежечасно днём: неудачная отправка повторится через час,
 * reminded_on не даёт написать дважды за день.
 */
class RemindWholesaleLists extends Command
{
    protected $signature = 'wholesale:remind';

    protected $description = 'Напомнить оптовикам в WhatsApp о регулярной заявке';

    public function handle(WhatsAppClient $whatsapp): int
    {
        if (! $whatsapp->canSend()) {
            $this->info('WhatsApp не настроен — регулярные списки видны только на /opt');

            return self::SUCCESS;
        }

        $today = CarbonImmutable::now();
        $sent = 0;

        WholesaleList::query()
            ->where('remind_weekday', $today->dayOfWeekIso)
            ->where(fn ($q) => $q->whereNull('reminded_on')->orWhere('reminded_on', '<', $today->toDateString()))
            ->with('customer')
            ->orderBy('customer_id')->orderBy('name')
            ->get()
            ->groupBy('customer_id')
            ->each(function (Collection $lists) use ($whatsapp, $today, &$sent) {
                $customer = $lists->first()->customer;
                // Перестал быть оптовиком — /opt ему закрыт, звать туда незачем.
                if ($customer === null || ! $customer->seesWholesalePrices()) {
                    return;
                }
                if ($whatsapp->sendTo($customer->phone, self::text($lists))) {
                    WholesaleList::query()->whereKey($lists->modelKeys())->update(['reminded_on' => $today->toDateString()]);
                    $sent++;
                }
            });

        $this->info("Напоминаний отправлено: {$sent}");

        return self::SUCCESS;
    }

    /** @param Collection<int,WholesaleList> $lists */
    public static function text(Collection $lists): string
    {
        $lines = $lists->map(fn (WholesaleList $list) => "• «{$list->name}», ".count($list->items).' поз.: '
            .route('wholesale.index', ['list' => $list->id]));

        return implode("\n", [
            'Здравствуйте! Сегодня по графику оптовая заявка в '.config('app.name').':',
            ...$lines->all(),
            'По ссылке позиции уже отмечены — проверьте количество и отправьте. Цены сегодняшние.',
            'Отключить напоминание: /opt → «Мои списки» → «Не напоминать».',
        ]);
    }
}
