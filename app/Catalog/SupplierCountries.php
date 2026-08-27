<?php

namespace App\Catalog;

use App\Models\Supplier;

/**
 * Страна производства стекла.
 *
 * Для покупателя это второй вопрос после цены: на одну машину китайское
 * стекло и европейское различаются втрое, и выбирают именно по этому.
 * В прайсах страны нет — там, где она попадается («сборка Россия»,
 * «(Корея)»), речь о сборке автомобиля, а не о заводе стекла. Значит,
 * источник один: сам поставщик.
 *
 * Список неполный намеренно. Неверная страна тут — не косметика, а неверное
 * основание для выбора: человек доплатит за «Японию», которая окажется
 * китайской. Поэтому заполняем только бесспорное, остальное оставляем
 * менеджеру — в карточке поставщика поле открыто для правки.
 */
class SupplierCountries
{
    /**
     * Заводы, в происхождении которых нет сомнений.
     *
     * @var array<string,string>
     */
    private const KNOWN = [
        'agc' => 'Япония',          // Asahi Glass
        'fuyao' => 'Китай',           // Fuyao Glass Industry Group
        'xyg' => 'Китай',           // Xinyi Glass
        'nordglass' => 'Польша',          // NordGlass, Кошалин
        'pilkington' => 'Великобритания', // Pilkington
        'bor' => 'Россия',          // Борский стекольный завод
        'benson' => 'Китай',           // Шэньчжэнь

        /*
         * Подтверждено владельцем. Три завода из Бора Нижегородской области:
         * сам Борский стекольный, «Стекло-Люкс» (SL) и «БорАвтоСтекло» (BGC).
         */
        'кмк' => 'Россия',
        'спектр' => 'Россия',
        'sl' => 'Россия',           // «Стекло-Люкс», Бор
        'bgc' => 'Россия',          // «БорАвтоСтекло», Бор

        'китай' => 'Китай',           // не марка, а прямое указание в прайсе
    ];

    /**
     * @return array{filled:int,unknown:array<int,string>} заполнено и что осталось человеку
     */
    public function fill(): array
    {
        $filled = 0;
        $unknown = [];

        foreach (Supplier::all() as $supplier) {
            if (filled($supplier->country)) {
                continue;   // менеджер уже поставил — не трогаем
            }

            $country = self::KNOWN[mb_strtolower(trim($supplier->name))] ?? null;

            if ($country === null) {
                // «Оригинал» и «Не указан» — не заводы, спрашивать про них нечего
                if (! $this->isFactory($supplier->name)) {
                    continue;
                }

                $unknown[] = $supplier->name;

                continue;
            }

            $supplier->update(['country' => $country]);
            $filled++;
        }

        return ['filled' => $filled, 'unknown' => $unknown];
    }

    /** «Оригинал» и «Не указан» — способ заполнить пустоту в прайсе, а не завод. */
    private function isFactory(string $name): bool
    {
        return ! in_array(mb_strtolower(trim($name)), ['оригинал', 'не указан'], true);
    }
}
