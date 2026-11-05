<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Catalog\OfferVehicleConstraints;
use App\Domain\Catalog\SearchOffers;
use App\Services\PriceList\BrandDictionary;

/** Turns a shopper sentence into a catalogue query and drops unrelated offers. */
final class AssistantSearch
{
    /** @var list<string> */
    private const STOPWORDS = [
        'лобовое', 'лобовуха', 'лобовуху', 'стекло', 'стекла', 'стёкла', 'заднее', 'боковое',
        'год', 'года', 'году', 'какие', 'какой', 'какая', 'варианты', 'вариант', 'варианта',
        'наличие', 'наличии', 'сейчас', 'нужно', 'нужна', 'нужен', 'покажи', 'подбери',
        'windshield', 'glass', 'what', 'options',
        'пожалуйста', 'хочу', 'ищу', 'найди', 'найти', 'купить', 'автомобиль', 'машина', 'авто', 'для', 'на',
        'раньше', 'написал', 'исправляю', 'исправь', 'ошибся', 'артикул', 'артикулу', 'еврокод',
        'оптовая', 'заявка', 'штук', 'штуки', 'штука', 'камера', 'камерой', 'камеры',
        'датчик', 'датчиком', 'дождя', 'обогрев', 'обогревом', 'полный', 'полным', 'проекция', 'проекцией',
        'алдыңғы', 'маңдай', 'артқы', 'бүйір', 'бүйірлік', 'жанындағы',
        'әйнек', 'әйнегі', 'әйнегін', 'әйнекті', 'терезе', 'терезесі',
        'керек', 'қажет', 'қалаймын', 'іздеймін', 'тап', 'табыңыз', 'көрсет', 'көрсетіңіз',
        'таңда', 'таңдаңыз', 'таңдауға', 'сатып', 'алу', 'бұрын', 'дедім', 'жаздым', 'түзетемін', 'қателестім',
        'артикулын', 'артикулы', 'еурокод', 'көтерме', 'өтінім', 'дана', 'жыл', 'жылғы', 'жылы',
        'көлік', 'көлікті', 'машинаға', 'үшін', 'бар', 'жоқ', 'баға', 'бағасы', 'қанша',
        'камерасы', 'камерасыз', 'жаңбыр', 'датчигі', 'толық', 'жылытылатын', 'жабдықталуын', 'білмеймін',
    ];

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public static function parameters(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return $args;
        }

        if (! isset($args['year']) && preg_match_all('/(?<![\d–—-])((?:19|20)\d{2})(?![\d–—-])/u', $query, $years) && $years[1] !== []) {
            $args['year'] = (int) end($years[1]);
        }
        if (empty($args['glass_type']) && ($glass = self::glassType($query)) !== null) {
            $args['glass_type'] = $glass;
        }
        $extractedOptions = self::options($query);
        if ($extractedOptions !== []) {
            $args['options'] = array_replace(is_array($args['options'] ?? null) ? $args['options'] : [], $extractedOptions);
        }

        $focused = self::focusTerm($query);
        if ($focused !== '') {
            $args['query'] = $focused;
        }

        return $args;
    }

    /**
     * Keep offers that mention the model code or article references from the question.
     * A bare year such as 1998 is not a model and must not pull every car of that year.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function keepRelevant(array $items, string $question): array
    {
        $references = self::references($question);
        $needles = $references !== [] ? $references : self::codes($question);
        $matchAnyNeedle = count($references) > 1;
        if ($needles === []) {
            $term = self::focusTerm($question);
            $needles = $term === '' ? [] : [$term];
        }
        $vehicle = self::parameters(['query' => $question]);
        $spaced = self::spacedReference($question);

        return array_values(array_filter($items, function (array $item) use ($needles, $matchAnyNeedle, $vehicle, $spaced): bool {
            if (! OfferVehicleConstraints::allows($item, $vehicle)) {
                return false;
            }
            $name = mb_strtolower((string) ($item['name'] ?? '').' '.(string) ($item['article'] ?? '').' '.(string) ($item['eurocode'] ?? ''));
            if ($spaced !== null) {
                foreach (['article', 'eurocode', 'oem_code', 'internal_code'] as $field) {
                    if (SearchOffers::normalizeCode((string) ($item[$field] ?? '')) === SearchOffers::normalizeCode($spaced)) {
                        return true;
                    }
                }

                return str_contains($name, mb_strtolower($spaced));
            }
            if ($matchAnyNeedle) {
                foreach ($needles as $needle) {
                    if (str_contains($name, mb_strtolower($needle))) {
                        return true;
                    }
                }

                return false;
            }
            foreach ($needles as $needle) {
                if (! str_contains($name, mb_strtolower($needle))) {
                    return false;
                }
            }

            return true;
        }));
    }

    public static function focusTerm(string $query): string
    {
        $references = self::references($query);
        if ($references !== []) {
            return $references[0];
        }
        if (($spaced = self::spacedReference($query)) !== null) {
            return $spaced;
        }
        $codes = self::codes($query);
        if ($codes !== []) {
            return implode(' ', $codes);
        }

        $words = self::words($query);
        $modelWords = array_values(array_filter($words, fn ($word) => BrandDictionary::canonical($word) === null));
        if ($modelWords !== []) {
            $words = $modelWords;
        }
        if ($words === []) {
            return '';
        }
        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $words[0];
    }

    public static function spacedReference(string $query): ?string
    {
        if (preg_match_all('/(?<![\p{L}\p{N}_])([a-zA-Z]{2,20})\s+(\d{3,8})(?![\p{L}\p{N}])/u', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (! in_array(mb_strtolower($match[1]), ['id', 'offer'], true)
                    && ! preg_match('/^(?:19|20)\d{2}$/D', $match[2]) && BrandDictionary::canonical($match[1]) === null) {
                    return $match[1].' '.$match[2];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function references(string $query): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])([a-zA-Z0-9][a-zA-Z0-9._-]{4,})(?![\p{L}\p{N}])/u', $query, $matches);
        $refs = [];
        foreach ($matches[1] as $reference) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $reference) || preg_match('/^(?:19|20)\d{2}[–—-](?:19|20)\d{2}$/u', $reference)) {
                continue;
            }
            $hasLetter = preg_match('/[a-zA-Z]/', $reference) === 1;
            $hasDigit = preg_match('/\d/', $reference) === 1;
            $hasHyphen = str_contains($reference, '-') && preg_match('/[a-zA-Z]{2,}/', $reference) === 1;
            if ($hasLetter && ($hasDigit || $hasHyphen)) {
                $refs[] = $reference;
            }
        }

        return array_values(array_unique($refs));
    }

    /** @return array<string,int> */
    public static function lineQuantities(string $query): array
    {
        $quantities = [];
        foreach (self::references($query) as $ref) {
            $pattern = '/'.preg_quote($ref, '/').'(?:\s*[-—:×xх]\s*|\s+)(\d{1,3})(?:\s*(?:шт(?:ук[аи]?)?|дана|ед\.?|стекл[ао]|әйнек))?(?!\d)/ui';
            if (preg_match($pattern, $query, $match)) {
                $quantities[mb_strtoupper($ref)] = max(1, (int) $match[1]);
            } else {
                $quantities[mb_strtoupper($ref)] = 1;
            }
        }

        return $quantities;
    }

    /** @return array<string,bool|null> */
    public static function options(string $query): array
    {
        $options = [];
        if (preg_match('/камер\p{L}*\s+(?:не\s+знаю|білмеймін)|не\s+знаю.{0,15}камер/ui', $query)) {
            $options['camera'] = null;
        } elseif (preg_match('/без\s+камер|камер\p{L}*\s+(?:нет|жоқ)|камерасыз|без\s+adas|adas\s+жоқ/ui', $query)) {
            $options['camera'] = false;
        } elseif (preg_match('/камер[аыойу]|камерасы\s+бар|\badas\b/ui', $query)) {
            $options['camera'] = true;
        }

        if (preg_match('/без\s+датчик[ао]?\s+дожд|датчик\p{L}*\s+дожд\p{L}*\s+нет|жаңбыр\s+датчигінсіз|жаңбыр\s+датчигі\s+жоқ|датчиксіз/ui', $query)) {
            $options['rain_sensor'] = false;
        } elseif (preg_match('/датчик[ао]?м?\s+дожд|жаңбыр\s+датчиг[іi]|жаңбыр\s+сенсор/ui', $query)) {
            $options['rain_sensor'] = true;
        }

        if (preg_match('/без\s+(?:полного\s+)?обогрев|жылытусыз/ui', $query)) {
            $options['heated_full'] = false;
        } elseif (preg_match('/полн\p{L}*\s+обогрев|толық\s+жылыт/ui', $query)) {
            $options['heated_full'] = true;
        }

        if (preg_match('/обогрев\p{L}*\s+(?:зоны\s+)?(?:дворник|щ[её]ток)|тазалағыш\s+аймағ\p{L}*\s+жылыт/ui', $query)) {
            $options['heated_wipers'] = true;
        }

        if (preg_match('/без\s+(?:hud|проекци)|hud\s+жоқ|проекциясыз/ui', $query)) {
            $options['hud'] = false;
        } elseif (preg_match('/\bhud\b|проекци[яейю]/ui', $query)) {
            $options['hud'] = true;
        }

        return $options;
    }

    /** @return list<string> */
    private static function codes(string $query): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])([a-zA-Z]{1,4}\d{1,4}|\d{1,3}[a-zA-Z]{1,4})(?![\p{L}\p{N}])/u', $query, $matches);
        $codes = [];
        foreach ($matches[1] as $code) {
            if (preg_match('/^(?:19|20)\d{2}$/', $code) || in_array(mb_strtolower($code), ['adas', 'hud'], true)) {
                continue;
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    /** @return list<string> */
    private static function words(string $query): array
    {
        $stripped = preg_replace('/(?<![\p{N}])(?:19|20)\d{2}(?![\p{N}])/u', ' ', $query) ?? $query;
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($stripped), $matches);
        $words = [];
        foreach ($matches[0] as $word) {
            if (! in_array($word, self::STOPWORDS, true) && ! in_array($word, ['adas', 'hud'], true)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /** Explicit words only; catalogue resolution decides whether any is a unique model. */
    public static function modelTerms(string $query): array
    {
        return array_slice(array_values(array_unique(self::words($query))), 0, 12);
    }

    public static function glassType(string $query): ?string
    {
        return match (true) {
            preg_match('/лобов|windshield|алдыңғы|маңдай/ui', $query) === 1 => 'windshield',
            preg_match('/задн|артқы/ui', $query) === 1 => 'rear',
            preg_match('/боков|бүйір|жанындағы/ui', $query) === 1 => 'side',
            default => null,
        };
    }
}
