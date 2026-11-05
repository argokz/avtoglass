<?php

namespace Tests\Unit;

use App\Ai\OrderReplies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderRepliesTest extends TestCase
{
    public static function команды(): array
    {
        return [
            'отмена' => ['Отмени заказ 78', 'cancel_order', '78'],
            'отмена с номером АС' => ['хочу отменить заявку АС-000078, пожалуйста', 'cancel_order', '000078'],
            'оплата' => ['оплатить заказ 78', 'pay_order', '78'],
            'оплата латиницей' => ['как оплатить заказ AC-12?', 'pay_order', '12'],
            'статус' => ['статус заказа 15', 'get_order', '15'],
            'где заказ' => ['где мой заказ 15', 'get_order', '15'],
            'порядок наоборот' => ['заказ 78 отмените', 'cancel_order', '78'],
            'казахский' => ['78 тапсырысты төлеу', 'pay_order', '78'],
        ];
    }

    #[DataProvider('команды')]
    public function test_команда_по_заказу_распознаётся(string $text, string $tool, string $number): void
    {
        $this->assertSame(['name' => $tool, 'args' => ['order_number' => $number]], OrderReplies::command($text));
    }

    public static function незаказы(): array
    {
        return [['лобовое на камри 2015'], ['добавь 2'], ['отмена'], ['сколько стоит оплата картой'], ['мои заказы']];
    }

    #[DataProvider('незаказы')]
    public function test_прочие_сообщения_не_трогаем(string $text): void
    {
        $this->assertNull(OrderReplies::command($text));
    }

    public function test_ответ_про_отмену_зовёт_нажать_кнопку(): void
    {
        $text = OrderReplies::cancel(['order' => ['number' => 'АС-000078'], 'cancel' => ['allowed' => true, 'code' => null]], false);

        $this->assertStringContainsString('Подтвердите кнопкой', $text);
    }
}
