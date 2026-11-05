<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Data;

use Carbon\CarbonImmutable;

/** Compare immutable quote snapshots, without consulting today's catalogue or tariffs. */
final class ProposalComparison
{
    public static function rows(array $original, array $proposed, string $locale): array
    {
        $kk = $locale === 'kk';
        $before = self::terms($original, $kk);
        $after = self::terms($proposed, $kk);
        $labels = $kk ? ['Құрамы', 'Алу тәсілі', 'Қала', 'Мекенжай', 'Алу орны', 'Орнату уақыты', 'Төлем', 'Тауарлар', 'Жұмыстар', 'Жеткізу', 'Барлығы']
            : ['Состав', 'Получение', 'Город', 'Адрес', 'Пункт получения', 'Время установки', 'Оплата', 'Товары', 'Работы', 'Доставка', 'Итого'];
        $rows = [];
        foreach ($labels as $index => $label) {
            $rows[] = ['label' => $label, 'before' => $before[$index], 'after' => $after[$index], 'changed' => $before[$index] !== $after[$index]];
        }

        return $rows;
    }

    private static function terms(array $snapshot, bool $kk): array
    {
        $receiving = $snapshot['fulfillment'] ?? [];
        $type = $receiving['type'] ?? '';
        $point = $receiving['point'] ?? [];
        $items = array_map(fn ($line) => implode(' · ', array_filter([
            $line['name'] ?? $line['reference'] ?? '', $line['article'] ?? '', $line['manufacturer'] ?? '',
            ($line['quantity'] ?? 0).' '.($kk ? 'дана' : 'шт.'), self::money($line['total'] ?? null),
        ], fn ($value) => $value !== '')), $snapshot['items'] ?? []);
        $slot = empty($receiving['slot_start']) ? '—' : CarbonImmutable::parse($receiving['slot_start'])
            ->setTimezone($point['timezone'] ?? config('app.timezone'))->format('d.m.Y H:i P');
        if ($slot !== '—' && ! empty($receiving['slot_end'])) {
            $slot .= '–'.CarbonImmutable::parse($receiving['slot_end'])->setTimezone($point['timezone'] ?? config('app.timezone'))->format('H:i');
        }

        return [
            implode("\n", $items) ?: '—',
            $kk ? match ($type) {
                'pickup' => 'Өзі алып кету', 'delivery' => 'Жеткізу', 'installation' => 'Сервисте орнату', 'visit' => 'Көшпелі орнату', default => '—'
            }
                : match ($type) {
                    'pickup' => 'Самовывоз', 'delivery' => 'Доставка', 'installation' => 'Установка в сервисе', 'visit' => 'Выезд мастера', default => '—'
                },
            trim($receiving['city'] ?? '') ?: '—', trim($receiving['address'] ?? '') ?: '—',
            trim(($point['name'] ?? '').' '.($point['address'] ?? '')) ?: '—', $slot,
            $kk ? match ($snapshot['payment_method'] ?? '') {
                'cash' => 'Алған кезде төлеу', 'card' => 'Онлайн картамен', 'invoice' => 'Ұйымға шот', default => '—'
            }
                : match ($snapshot['payment_method'] ?? '') {
                    'cash' => 'При получении', 'card' => 'Картой онлайн', 'invoice' => 'Счёт на организацию', default => '—'
                },
            self::money($snapshot['goods_total'] ?? null), self::money($snapshot['services_total'] ?? null),
            self::money($snapshot['delivery_total'] ?? null), self::money($snapshot['total'] ?? null),
        ];
    }

    private static function money(?array $value): string
    {
        return isset($value['amount_minor']) ? number_format($value['amount_minor'] / 100, 2, ',', ' ').' '.($value['currency'] ?? 'KZT') : '—';
    }
}
