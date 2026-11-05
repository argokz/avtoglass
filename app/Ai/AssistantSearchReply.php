<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Product;

/** Search narration is composed from the same server facts as the offer cards. */
final class AssistantSearchReply
{
    /** @var array<string, string> */
    private const OPTION_LABELS_KK = [
        'rain_sensor' => 'Жаңбыр сенсоры',
        'camera' => 'ADAS бейнебақылау сенсоры',
        'hud' => 'Проекциялық дисплей (HUD)',
        'heated_full' => 'Толық электрлі жылыту',
        'heated_wipers' => 'Тазалағыш аймағын жылыту',
        'heated_rear' => 'Артқы әйнекті жылыту',
        'sun_band' => 'Күннен қорғайтын жолақ',
        'mirror_bracket' => 'Айна ұстағышы',
        'antenna' => 'Радиоқабылдағыш желісі',
        'rhd' => 'Оң жақ руль',
    ];

    public static function make(array $result, string $locale, array $intent = []): string
    {
        $kk = $locale === 'kk';
        if (isset($result['code'])) {
            return $kk ? 'Көлік пен әйнек параметрлерін нақтылаңыз.' : 'Уточните автомобиль и параметры стекла для поиска.';
        }
        $items = $result['items'] ?? [];
        if ($items === []) {
            return $kk ? 'Сұрауға сәйкес каталог нұсқасы табылмады. Модельді, жылын немесе әйнек кодын нақтылаңыз; менеджер таңдауды тексере алады.'
                : 'По этому запросу вариантов в каталоге не найдено. Уточните модель, год или код стекла; менеджер может проверить подбор.';
        }
        $missing = array_values(array_unique(array_merge(...array_map(fn ($item) => $item['missing_options'] ?? $item['fitment']['missing_options'] ?? [], $items))));
        $review = array_filter($items, fn ($item) => ($item['fitment']['status'] ?? '') !== 'matched');
        $reply = $kk ? 'Каталог нұсқалары төмендегі карточкаларда: баға, артикул және қор әрқайсысы үшін бөлек көрсетілген.'
            : 'Варианты каталога — в карточках ниже: цена, артикул и наличие указаны отдельно для каждого предложения.';
        if ($review) {
            $reply .= $kk ? ' Үйлесімділікті менеджер тексеруі керек.' : ' Совместимость пока требует проверки.';
        }
        if ($missing !== []) {
            $labels = array_map(fn ($flag) => self::optionLabel((string) $flag, $kk), array_slice($missing, 0, 1));
            $reply .= ($kk ? ' Нақтылаңыз: ' : ' Уточните: ').implode(', ', $labels).'.';
        }
        $reply .= $kk ? ' Жалғастыру үшін нақты карточканы таңдаңыз.' : ' Выберите конкретную карточку, чтобы перейти к оформлению.';
        if (($summary = AssistantOrderIntent::summary($intent, $locale)) !== '') {
            $reply .= "\n\n".$summary;
        }

        return $reply;
    }

    public static function optionLabel(string $flag, bool $kk = false): string
    {
        if ($kk) {
            return self::OPTION_LABELS_KK[$flag] ?? Product::OPTION_FLAGS[$flag] ?? $flag;
        }

        return Product::OPTION_FLAGS[$flag] ?? $flag;
    }
}
