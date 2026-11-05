<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\AssistantAnswer;
use App\Support\StorefrontCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class ApprovedAnswers
{
    public function reply(string $question, string $locale): ?string
    {
        if (! Schema::hasTable('assistant_answers') || mb_strlen($question) > 180) {
            return null;
        }
        $topic = match (true) {
            preg_match('/гаранти|кепілдік/ui', $question) === 1 => 'warranty',
            preg_match('/как\s+оплат|способы?\s+оплат|қалай\s+төле|төлем\s+тәсіл/ui', $question) === 1 => 'payment',
            preg_match('/условия\s+достав|как\s+достав|жеткізу\s+шарт/ui', $question) === 1
                || preg_match('/достав|жеткіз/ui', $question) && preg_match('/когда|сколько|бесплат|завтра|сегодня|тариф|қашан|қанша|тегін|ертең|бүгін/ui', $question) => 'delivery',
            preg_match('/как\s+проходит\s+установ|орнату\s+қалай/ui', $question) === 1 => 'installation',
            default => null,
        };
        if ($topic === null) {
            return null;
        }
        $answer = app(StorefrontCache::class)->remember('assistant-answer:'.$locale.':'.$topic, function () use ($topic, $locale) {
            return AssistantAnswer::query()->where('topic', $topic)->where('locale', $locale)->where('is_published', true)
                ->whereNotNull('source')->first(['title', 'answer', 'source', 'valid_until'])?->toArray();
        }, 60);
        if ($answer && (! filled($answer['source'] ?? null) || ! empty($answer['valid_until']) && CarbonImmutable::parse($answer['valid_until'])->lte(now()))) {
            $answer = null;
        }

        return $answer ? $answer['title']."\n".$answer['answer'] : ($locale === 'kk'
            ? 'Бұл шарттар бойынша расталған ақпарат жоқ. Менеджерге сұрау жіберіңіз.'
            : 'Подтверждённой информации по этим условиям пока нет. Передайте вопрос менеджеру.');
    }
}
