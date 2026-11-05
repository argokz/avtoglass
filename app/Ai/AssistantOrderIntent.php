<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Identity\BuyerContext;
use App\Models\Customer;

/** Explicit shopping requirements from persisted customer messages, independent of model guesses. */
final class AssistantOrderIntent
{
    /** @var array<string, string> */
    private const CITY_ALIASES = [
        'қарағанды' => 'Караганда',
        'караганда' => 'Караганда',
        'караганду' => 'Караганда',
        'караганде' => 'Караганда',
        'алматы' => 'Алматы',
        'астана' => 'Астана',
        'астану' => 'Астана',
        'астане' => 'Астана',
        'шымкент' => 'Шымкент',
        'актобе' => 'Актобе',
        'ақтөбе' => 'Актобе',
        'атырау' => 'Атырау',
        'костанай' => 'Костанай',
        'қостанай' => 'Костанай',
        'павлодар' => 'Павлодар',
        'усть-каменогорск' => 'Усть-Каменогорск',
        'өскемен' => 'Усть-Каменогорск',
        'семей' => 'Семей',
        'уральск' => 'Уральск',
        'орал' => 'Уральск',
        'тараз' => 'Тараз',
        'кызылорда' => 'Кызылорда',
        'қызылорда' => 'Кызылорда',
        'актау' => 'Актау',
        'ақтау' => 'Актау',
    ];

    public static function fromMessages(array $messages, array $cities = []): array
    {
        $intent = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $text = trim((string) ($message['text'] ?? ''));
            // The selection button quotes a product title, not a new vehicle year or service request.
            if (preg_match('/^(?:беру\s+)?offer_id\s+\d+(?:\s+аламын)?\s*:/ui', $text)) {
                continue;
            }
            if (preg_match('/\boffer_id\s+(\d+)\b/ui', $text, $selectedOffer)) {
                $intent['offer_id'] = (int) $selectedOffer[1];
            }
            preg_match_all('/(?<![\d–—-])((?:19|20)\d{2})(?![\d–—-])/u', $text, $years);
            if ($years[1] !== []) {
                $intent['year'] = (int) end($years[1]);
            }
            if (preg_match('/\b(\d{1,3})\s*(?:шт\.?|штук\w*|ст[её]кл\w*|дана)\b/ui', $text, $quantity)) {
                $intent['quantity'] = (int) $quantity[1];
            }
            $lineQuantities = AssistantSearch::lineQuantities($text);
            if (count($lineQuantities) > 1) {
                $intent['line_quantities'] = $lineQuantities;
            }
            $extractedOptions = AssistantSearch::options($text);
            if ($extractedOptions !== []) {
                $intent['options'] = array_replace(is_array($intent['options'] ?? null) ? $intent['options'] : [], $extractedOptions);
            }
            $withoutInstallation = preg_match('/без\s+установк|установк[а-я]*\s+не\s+(?:нужн|надо)|орнатусыз/ui', $text) === 1;
            if ($withoutInstallation) {
                $intent['without_installation'] = true;
            }
            $positive = preg_replace('/без\s+установк[а-я]*|установк[а-я]*\s+не\s+(?:нужн[а-я]*|надо)|орнатусыз/ui', '', $text) ?? $text;
            if (preg_match('/самовывоз|өз[іi]м?\s+алып\s+кет/ui', $positive)) {
                $intent['fulfillment'] = 'pickup';
                unset($intent['address']);
            } elseif (preg_match('/выезд|көшпелі\s+орнат/ui', $positive)) {
                $intent['fulfillment'] = 'visit';
                $intent['without_installation'] = false;
            } elseif (preg_match('/доставк|жеткіз/ui', $positive)) {
                $intent['fulfillment'] = 'delivery';
            } elseif (preg_match('/установк|орнат/ui', $positive)) {
                $intent['fulfillment'] = 'installation';
                $intent['without_installation'] = false;
            } elseif ($withoutInstallation && in_array($intent['fulfillment'] ?? null, ['installation', 'visit'], true)) {
                unset($intent['fulfillment']);
            }
            if (preg_match('/(?:доставк[а-я]*|жеткізу|адрес\s*[:—-]?)\s*(?:в\s+)?([\p{L}][\p{L}\s-]{1,59}),\s*(.+)$/ui', $positive, $location)) {
                $intent['city'] = self::city(trim($location[1]), $cities);
                $intent['address'] = mb_substr(trim($location[2], " \t\n\r\0\x0B,;"), 0, 200);
            } elseif (preg_match('/(?:,\s*|^)([\p{L}-]+)\s+([^,.\n]+?)\s+жеткіз[уі]/ui', $positive, $kkLocation)) {
                $intent['city'] = self::city(trim($kkLocation[1]), $cities);
                $intent['address'] = mb_substr(trim($kkLocation[2], " \t\n\r\0\x0B,;"), 0, 200);
            } elseif (preg_match('/\b(?:город|қала)\s*:?\s*([\p{L}][\p{L}\s-]{1,59})(?:,|$)/ui', $text, $city)) {
                $intent['city'] = self::city(trim($city[1]), $cities);
            } elseif (preg_match('/\b(?:адрес|мекенжай)\s*:\s*(.+)$/ui', $text, $address)) {
                $intent['address'] = mb_substr(trim($address[1]), 0, 200);
            } elseif (($matchedCity = self::detectCityInText($positive, $cities)) !== null) {
                $intent['city'] = $matchedCity;
            }
            if (preg_match('/(?:\+?7|8)[\s()-]*(?:\d[\s()-]*){9}\d/u', $text, $phoneMatch)) {
                $intent['phone'] = trim($phoneMatch[0]);
            }
            if (preg_match('/(?:имя|аты(?:-жөні)?|контакт)\s*[:—-]\s*([\p{L}][\p{L}\s-]{1,60}?)(?=[,.\n]|$)/ui', $text, $nameMatch)) {
                $intent['name'] = trim($nameMatch[1]);
            } elseif (preg_match('/(?:[:.]\s*|^)([\p{Lu}][\p{L}-]+(?:\s+[\p{Lu}][\p{L}-]+){0,2})\s*,\s*(?:\+?7|8)[\s()-]*\d/u', $text, $nameBeforePhone)) {
                $intent['name'] = trim($nameBeforePhone[1]);
            }
        }

        return $intent;
    }

    public static function selectedOfferId(array $messages): ?int
    {
        $offerId = null;
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            if (preg_match('/\boffer_id\s+(\d+)\b/ui', (string) ($message['text'] ?? ''), $match)) {
                $offerId = (int) $match[1];
            }
        }

        return $offerId;
    }

    public static function checkoutArguments(array $args, array $intent, ?BuyerContext $buyer = null): array
    {
        if ($buyer?->customerId !== null && ($customer = Customer::query()->find($buyer->customerId)) !== null) {
            if (empty($args['name']) && trim((string) $customer->name) !== '') {
                $args['name'] = trim((string) $customer->name);
            }
            if (empty($args['phone']) && trim((string) $customer->phone) !== '') {
                $args['phone'] = trim((string) $customer->phone);
            }
            if (empty($args['fulfillment']) && empty($intent['fulfillment']) && ! empty($customer->preferred_fulfillment)) {
                $args['fulfillment'] = (string) $customer->preferred_fulfillment;
            }
            if (empty($args['city']) && empty($intent['city']) && ! empty($customer->default_city)) {
                $args['city'] = (string) $customer->default_city;
            }
            if (empty($args['address']) && empty($intent['address']) && ! empty($customer->default_address)) {
                $args['address'] = (string) $customer->default_address;
            }
        }
        foreach (['name', 'phone', 'offer_id', 'car_model_id', 'brand_id', 'generation_id', 'body', 'doors', 'year', 'quantity', 'fulfillment', 'city', 'address', 'options'] as $field) {
            if (isset($intent[$field])) {
                $args[$field] = $intent[$field];
            }
        }
        if (($intent['without_installation'] ?? false) || in_array($args['fulfillment'] ?? null, ['delivery', 'pickup'], true)) {
            unset($args['slot_start'], $args['slot_end']);
        }
        if (($intent['without_installation'] ?? false) && in_array($args['fulfillment'] ?? null, ['installation', 'visit'], true)) {
            unset($args['fulfillment']);
        }
        if (($args['fulfillment'] ?? null) === 'delivery') {
            unset($args['service_point_id']);
        }
        if (($args['fulfillment'] ?? null) === 'pickup') {
            unset($args['address']);
        }
        if ($intent['without_installation'] ?? false) {
            $comment = trim((string) ($args['comment'] ?? ''));
            if (! str_contains(mb_strtolower($comment), 'без установки')) {
                $args['comment'] = trim($comment.' Без установки.');
            }
        }

        return $args;
    }

    public static function summary(array $intent, string $locale): string
    {
        $kk = $locale === 'kk';
        $type = match ($intent['fulfillment'] ?? null) {
            'delivery' => $kk ? 'жеткізу' : 'доставка',
            'pickup' => $kk ? 'өзі алып кету' : 'самовывоз',
            'installation' => $kk ? 'сервисте орнату' : 'установка в сервисе',
            'visit' => $kk ? 'көшпелі орнату' : 'выездная установка',
            default => null,
        };
        $details = array_filter([$type, $intent['city'] ?? null, $intent['address'] ?? null]);
        $summary = $details === [] ? '' : ($kk ? 'Алу тәсілі: ' : 'Получение: ').implode(', ', $details).'.';
        if ($intent['without_installation'] ?? false) {
            $summary .= ($summary !== '' ? ' ' : '').($kk ? 'Орнатусыз.' : 'Без установки.');
        }
        if (! empty($intent['line_quantities']) && is_array($intent['line_quantities'])) {
            $pairs = [];
            foreach ($intent['line_quantities'] as $code => $qty) {
                $pairs[] = $code.' × '.$qty.($kk ? ' дана' : ' шт.');
            }
            $summary .= ($summary !== '' ? ' ' : '').($kk ? 'Тізім: ' : 'Спецификация: ').implode(', ', $pairs).'.';
        } elseif (($intent['quantity'] ?? 1) > 1) {
            $summary .= ' '.($kk ? 'Саны: ' : 'Количество: ').$intent['quantity'].($kk ? ' дана.' : ' шт.');
        }
        if (($intent['fulfillment'] ?? null) === 'delivery') {
            $summary .= ' '.($kk ? 'Жеткізу құны есептеу кезінде тексеріледі.' : 'Стоимость доставки проверим при расчёте.');
        }

        return trim($summary);
    }

    private static function detectCityInText(string $text, array $cities): ?string
    {
        foreach (self::CITY_ALIASES as $alias => $canonical) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($alias, '/').'(?![\p{L}])/ui', $text)) {
                return self::city($canonical, $cities);
            }
        }
        foreach ($cities as $city) {
            if (is_string($city) && $city !== '' && preg_match('/(?<![\p{L}])'.preg_quote($city, '/').'(?![\p{L}])/ui', $text)) {
                return $city;
            }
        }

        return null;
    }

    private static function city(string $name, array $cities): string
    {
        $lower = mb_strtolower($name);
        if (isset(self::CITY_ALIASES[$lower])) {
            $name = self::CITY_ALIASES[$lower];
        }
        $stem = fn ($value) => preg_replace('/[ауые]$/u', '', mb_strtolower($value));
        foreach ($cities as $city) {
            if (is_string($city) && $stem($name) === $stem($city)) {
                return $city;
            }
        }

        return $name;
    }
}
