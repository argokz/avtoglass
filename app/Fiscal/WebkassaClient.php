<?php

namespace App\Fiscal;

use App\Models\Receipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Webkassa — онлайн-касса (ОФД). Денег через неё не проходит: она выбивает
 * фискальный чек и передаёт его в налоговую после того, как деньги уже
 * получены эквайером.
 *
 * Токен живёт долго и кешируется: у Webkassa есть ограничение на частоту
 * авторизаций, и запрашивать его на каждый чек — верный способ упереться
 * в блокировку в первый же час распродажи.
 */
class WebkassaClient
{
    /** Продажа. У Webkassa операции нумерованные: 2 — продажа, 3 — возврат. */
    private const OPERATION_SALE = 2;

    private const OPERATION_RETURN = 3;

    /** Тип оплаты в чеке: наличные — 0, карта — 1. */
    private const PAYMENT_CASH = 0;

    private const PAYMENT_CARD = 1;

    /** НДС не облагается — большинство позиций у нас без НДС до перехода на ОСН. */
    private const TAX_TYPE_NONE = 0;

    public function token(): ?string
    {
        $key = 'webkassa:token:'.md5((string) config('fiscal.webkassa.login'));

        return Cache::remember($key, now()->addHours(6), function () {
            $response = Http::timeout(20)->post($this->url('Authorize'), [
                'Login' => config('fiscal.webkassa.login'),
                'Password' => config('fiscal.webkassa.password'),
            ]);

            return $response->json('Data.Token');
        });
    }

    /**
     * Выбивает чек. Возвращает данные чека или null — решение о повторе
     * принимает вызывающий код, здесь исключения не бросаем: неудачная
     * фискализация не должна выглядеть как сбой оплаты.
     *
     * @return array{check_number:string,ticket_url:?string,datetime:?string,raw:array}|null
     */
    public function issue(Receipt $receipt): ?array
    {
        return $this->submit($receipt, self::OPERATION_SALE);
    }

    /**
     * Чек возврата. Отдельная операция, а не отмена продажи: налоговая
     * должна видеть оба документа, иначе кассовая книга не сойдётся.
     *
     * @return array{check_number:string,ticket_url:?string,datetime:?string,raw:array}|null
     */
    public function refund(Receipt $receipt, ?Receipt $original = null): ?array
    {
        return $this->submit($receipt, self::OPERATION_RETURN, $original);
    }

    /**
     * @return array{check_number:string,ticket_url:?string,datetime:?string,raw:array}|null
     */
    private function submit(Receipt $receipt, int $operation, ?Receipt $original = null): ?array
    {
        $receipt->loadMissing(['order', 'payment']);

        $token = $this->token();

        if ($token === null) {
            return null;
        }

        $payload = [
            'Token' => $token,
            'CashboxUniqueNumber' => config('fiscal.webkassa.cashbox'),
            'OperationType' => $operation,
            'Positions' => $this->positions($receipt),
            'Payments' => [[
                'Sum' => round((float) $receipt->amount, 2),
                'PaymentType' => $receipt->payment?->isCash()
                    ? self::PAYMENT_CASH
                    : self::PAYMENT_CARD,
            ]],
            'Change' => 0,
            'RoundType' => 0,
            'ExternalCheckNumber' => $receipt->order->number,
        ];

        if ($original?->check_number) {
            $payload['ExternalCheckNumber'] = $receipt->order->number.'-R';
        }

        $response = Http::timeout(30)->post($this->url('Check'), $payload);

        $data = $response->json('Data');

        if (! $response->successful() || empty($data['CheckNumber'])) {
            return null;
        }

        return [
            'check_number' => (string) $data['CheckNumber'],
            'ticket_url' => $data['TicketUrl'] ?? null,
            'datetime' => $data['DateTime'] ?? null,
            'raw' => $response->json(),
        ];
    }

    /** Текст ошибки от кассы — его кладём в чек, чтобы было что чинить. */
    public function lastError(array $response): ?string
    {
        $errors = $response['Errors'] ?? [];

        if ($errors === []) {
            return null;
        }

        return collect($errors)
            ->map(fn ($e) => is_array($e) ? ($e['Text'] ?? json_encode($e)) : (string) $e)
            ->implode('; ');
    }

    /**
     * Позиции чека. Товары берём снимком из заказа — в чеке должно стоять
     * то, что покупали, а не то, как карточка называется сегодня.
     */
    private function positions(Receipt $receipt): array
    {
        $items = $receipt->order->items;

        if ($items->isEmpty()) {
            // Заказ без позиций всё равно надо фискализировать: деньги получены.
            return [[
                'Count' => 1,
                'Price' => round((float) $receipt->amount, 2),
                'TaxPercent' => 0,
                'TaxType' => self::TAX_TYPE_NONE,
                'PositionName' => 'Оплата по заявке '.$receipt->order->number,
                'PositionCode' => (string) $receipt->order->id,
            ]];
        }

        return $items->map(fn ($item) => [
            'Count' => (int) $item->quantity,
            'Price' => round((float) $item->price, 2),
            'TaxPercent' => 0,
            'TaxType' => self::TAX_TYPE_NONE,
            'PositionName' => mb_substr((string) $item->name, 0, 120),
            'PositionCode' => (string) ($item->product_id ?? $item->id),
        ])->values()->all();
    }

    private function url(string $method): string
    {
        $base = config('fiscal.webkassa.test_mode')
            ? config('fiscal.webkassa.endpoints.test')
            : config('fiscal.webkassa.endpoints.live');

        return rtrim((string) $base, '/').'/api/'.$method;
    }
}
