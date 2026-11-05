<?php

namespace App\Support\Logging;

use Monolog\LogRecord;

/**
 * Маскирует телефоны и почты в логах.
 *
 * Логи живут дольше заявок и читаются шире, чем CRM: деплой, сбросы,
 * внешние сборщики. Персональным данным там не место (ЗРК «О персональных
 * данных»), поэтому номер превращается в +7••••••52-08, а почта — в a•••@host.
 * По маске запись всё ещё можно сшить с заказом, а вот украсть из неё
 * контакт уже нельзя.
 */
class MaskPersonalData
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->mask($record->message),
            context: $this->maskArray($record->context),
        );
    }

    private function maskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->mask($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->maskArray($value);
            }
        }

        return $data;
    }

    private function mask(string $text): string
    {
        // Телефоны РК: +7 700 123-45-67, 87001234567 и т.п.
        $text = preg_replace_callback(
            '/(?<!\d)(?:\+?7|8)[\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-()]*\d{2}[\s\-()]*\d{2}(?!\d)/',
            function (array $m): string {
                $digits = preg_replace('/\D+/', '', $m[0]) ?? '';

                return '+7••••••'.substr($digits, -4);
            },
            $text,
        ) ?? $text;

        // Почты.
        return preg_replace(
            '/[A-Za-z0-9._%+-]+(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            '•••$1',
            $text,
        ) ?? $text;
    }
}
