<?php

namespace App\Models;

use App\Ai\AssistantSearch;
use App\Ai\Runtime\MessageText;
use App\Ai\Runtime\ToolPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Один завершённый ход помощника: вопрос, ответ и показанные карточки.
 */
class AiExchangeLog extends Model
{
    protected $guarded = [];

    public function getQuestionAttribute(?string $value): ?string
    {
        return $value === null ? null : MessageText::decode($value);
    }

    public function setQuestionAttribute(?string $value): void
    {
        $this->attributes['question'] = $value === null ? null : MessageText::encode($value);
    }

    public function getAnswerAttribute(?string $value): ?string
    {
        return $value === null ? null : MessageText::decode($value);
    }

    public function setAnswerAttribute(?string $value): void
    {
        $this->attributes['answer'] = $value === null ? null : MessageText::encode($value);
    }

    public function getSearchQueryAttribute(?string $value): ?string
    {
        return $value === null ? null : MessageText::decode($value);
    }

    public function setSearchQueryAttribute(?string $value): void
    {
        $this->attributes['search_query'] = $value === null ? null : MessageText::encode($value);
    }

    protected function casts(): array
    {
        return [
            'raw_offers' => 'array',
            'shown_offers' => 'array',
        ];
    }

    public static function recordTurn(string $turnId): void
    {
        if (! Schema::hasTable('ai_exchange_logs')) {
            return;
        }

        $turn = DB::table('ai_turns')->where('id', $turnId)->first();
        if ($turn === null || ! in_array($turn->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $question = MessageText::decode((string) DB::table('ai_messages')->where('id', $turn->user_message_id)->value('text'));
        $answer = MessageText::decode((string) DB::table('ai_messages')->where('id', $turn->model_message_id)->value('text'));
        $tool = DB::table('ai_tool_results')
            ->where('turn_id', $turnId)
            ->where('name', 'search_products')
            ->orderByDesc('id')
            ->first();

        $searchQuery = null;
        $raw = [];
        if ($tool !== null) {
            $args = ToolPayload::decode($tool->arguments);
            $result = ToolPayload::decode($tool->result);
            $searchQuery = is_array($args) ? ($args['query'] ?? null) : null;
            foreach (is_array($result) ? ($result['items'] ?? []) : [] as $item) {
                if (is_array($item) && is_string($item['name'] ?? null) && $item['name'] !== '') {
                    $raw[] = mb_substr($item['name'], 0, 180);
                }
            }
        }

        $shown = AssistantSearch::keepRelevant(
            array_map(fn (string $name) => ['name' => $name], $raw),
            trim($question.' '.(string) $searchQuery),
        );

        static::query()->updateOrCreate(
            ['turn_id' => $turnId],
            [
                'conversation_id' => $turn->conversation_id,
                'question' => self::mask(mb_substr($question, 0, 1000)),
                'answer' => $answer === '' ? null : self::mask(mb_substr($answer, 0, 2000)),
                'status' => $turn->status,
                'error_code' => $turn->error_code,
                'search_query' => is_string($searchQuery) ? mb_substr($searchQuery, 0, 190) : null,
                'raw_offers' => $raw,
                'shown_offers' => array_values(array_map(
                    fn (array $item) => $item['name'],
                    $shown,
                )),
            ],
        );
    }

    private static function mask(string $text): string
    {
        $text = preg_replace_callback(
            '/(?<!\d)(?:\+?7|8)[\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-()]*\d{2}[\s\-()]*\d{2}(?!\d)/',
            function (array $match): string {
                $digits = preg_replace('/\D+/', '', $match[0]) ?? '';

                return '+7••••••'.substr($digits, -4);
            },
            $text,
        ) ?? $text;

        return preg_replace(
            '/[A-Za-z0-9._%+-]+(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            '•••$1',
            $text,
        ) ?? $text;
    }
}
