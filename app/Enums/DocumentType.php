<?php

namespace App\Enums;

/**
 * Выходные документы.
 *
 * Список намеренно короче того, что бывает в интернет-торговле вообще:
 * сюда попало только то, что нужно стекольному бизнесу с собственной
 * доставкой. Маркетплейсных актов и СНТ здесь нет — автостекло не подакцизное
 * и через фулфилмент не идёт.
 */
enum DocumentType: string
{
    case SalesReceipt = 'sales_receipt';    // товарный чек / расходная накладная
    case Warranty = 'warranty';         // гарантийный талон
    case HandoverAct = 'handover_act';     // акт приёма-передачи покупателю
    case Invoice = 'invoice';          // счёт на оплату (B2B)
    case CourierManifest = 'courier_manifest'; // реестр отправлений курьеру

    public function label(): string
    {
        return match ($this) {
            self::SalesReceipt => 'Товарный чек',
            self::Warranty => 'Гарантийный талон',
            self::HandoverAct => 'Акт приёма-передачи',
            self::Invoice => 'Счёт на оплату',
            self::CourierManifest => 'Реестр отправлений',
        };
    }

    /** Буквенный префикс в номере документа. */
    public function prefix(): string
    {
        return match ($this) {
            self::SalesReceipt => 'ТЧ',
            self::Warranty => 'ГТ',
            self::HandoverAct => 'АПП',
            self::Invoice => 'СЧ',
            self::CourierManifest => 'РО',
        };
    }

    /** Документы курьера привязаны к смене, а не к заказу. */
    public function belongsToOrder(): bool
    {
        return $this !== self::CourierManifest;
    }

    public function view(): string
    {
        return 'documents.'.str_replace('_', '-', $this->value);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $acc, self $t) => $acc + [$t->value => $t->label()],
            []
        );
    }
}
