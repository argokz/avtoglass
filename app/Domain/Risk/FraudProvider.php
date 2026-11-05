<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/**
 * Провайдер антифрод-оценки.
 *
 * По умолчанию работает локальный: velocity по телефону и IP, стоп-лист,
 * крупные суммы. Внешний провайдер (банковский скоринг, специализированный
 * сервис) подключается реализацией этого интерфейса и сменой risk.provider —
 * точки вызова в оформлении заказа менять не придётся.
 */
interface FraudProvider
{
    public function assess(FraudContext $context): RiskVerdict;
}
