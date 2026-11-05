<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Заказ текстом: «отмени заказ 78», «оплатить АС-000078», «статус заявки 78».
 *
 * Такие просьбы распознаём сами, не отдавая модели: номер и действие здесь
 * однозначны, а угадывать заказ модели нельзя. Сам инструмент ничего не
 * отменяет и не списывает — он показывает карточку, и решает человек кнопкой.
 */
final class OrderReplies
{
    private const NUMBER = '(?:(?:АС|AC)\s*-?\s*)?(\d{1,6})';

    private const ORDER_WORD = '(?:заказ|заявк|тапсырыс|өтінім)\w*';

    /** @return array{name:string,args:array<string,string>}|null */
    public static function command(string $question): ?array
    {
        $text = trim($question);
        $actions = [
            'cancel_order' => '(?:отмен\w*|отказ\w*|бас\s+тарт\w*|болдырма\w*)',
            'pay_order' => '(?:оплат\w*|заплат\w*|төле\w*)',
            'get_order' => '(?:статус\w*|где|что\s+с|қайда|жағдай\w*|мәртебе\w*)',
        ];
        foreach ($actions as $tool => $verb) {
            $pattern = '/'.$verb.'.{0,24}?'.self::ORDER_WORD.'\D{0,12}?'.self::NUMBER.'(?!\d)/ui';
            $reversed = '/'.self::ORDER_WORD.'\D{0,12}?'.self::NUMBER.'(?!\d).{0,24}?'.$verb.'/ui';
            // По-казахски номер идёт перед словом: «78 тапсырысты төлеу».
            $numberFirst = '/(?<!\d)'.self::NUMBER.'\s*-?\w{0,3}\s+'.self::ORDER_WORD.'.{0,24}?'.$verb.'/ui';
            if (preg_match($pattern, $text, $match) || preg_match($reversed, $text, $match) || preg_match($numberFirst, $text, $match)) {
                return ['name' => $tool, 'args' => ['order_number' => $match[1]]];
            }
        }

        return null;
    }

    public static function cancel(array $result, bool $kk): string
    {
        $number = $result['order']['number'] ?? '';

        return match ($result['cancel']['code'] ?? null) {
            null => $kk
                ? "{$number} тапсырысынан бас тартасыз ба? Төмендегі «Бас тарту» батырмасымен растаңыз."
                : "Отменить заказ {$number}? Подтвердите кнопкой «Отменить заказ» ниже.",
            'ALREADY_CANCELLED' => $kk ? "{$number} тапсырысынан бас тартылған." : "Заказ {$number} уже отменён.",
            'PAYMENT_EXISTS' => $kk
                ? "{$number} тапсырысы бойынша төлем бар. Ақшаны қайтаруды менеджер ресімдейді — өзгерту сұрауын жіберіңіз."
                : "По заказу {$number} уже есть оплата. Отмену с возвратом оформит менеджер — отправьте запрос на изменение или позвоните.",
            default => $kk
                ? "{$number} тапсырысы расталған: әйнек резервте немесе уақыт белгіленген. Бас тартуды менеджер ресімдейді."
                : "Заказ {$number} уже согласован: стекло в резерве или назначено время. Отмену оформит менеджер — отправьте запрос или позвоните.",
        };
    }

    public static function pay(array $result, bool $kk): string
    {
        $order = $result['order'] ?? [];
        $number = $order['number'] ?? '';
        if ($order['paid'] ?? false) {
            return $kk ? "{$number} тапсырысы төленген." : "Заказ {$number} уже оплачен.";
        }
        if ($order['can_pay'] ?? false) {
            return $kk
                ? "{$number} тапсырысын төмендегі «Төлеу» батырмасымен төлеуге болады."
                : "Заказ {$number} можно оплатить кнопкой «Оплатить» ниже — откроется страница банка.";
        }

        return $kk
            ? "{$number} тапсырысын қазір онлайн төлеу мүмкін емес: менеджер бағаны мен қолжетімділікті растауы керек немесе төлем алған кезде."
            : "Заказ {$number} пока нельзя оплатить онлайн: сначала менеджер подтвердит наличие и цену, либо оплата выбрана при получении.";
    }
}
