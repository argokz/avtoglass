<?php

namespace App\Ai;

use App\Ai\Gemini\GeminiClient;

/**
 * Диалог с Gemini: история чата + инструменты каталога.
 *
 * Цикл «ответ → вызов инструмента → ответ» крутим здесь, а не в Livewire:
 * компоненту остаётся только показать строки. Ограничение в 5 ходов — от
 * бесконечного пинг-понга, когда модель зовёт инструменты по кругу.
 */
class AssistantDialog
{
    private const MAX_STEPS = 5;

    public function __construct(
        private readonly GeminiClient $client,
        private readonly AssistantTools $tools,
    ) {}

    /**
     * @param  list<array{role:string,text:string}>  $messages  История чата (user/model)
     */
    public function reply(array $messages): string
    {
        $contents = array_map(fn (array $message) => [
            'role' => $message['role'] === 'model' ? 'model' : 'user',
            'parts' => [['text' => $message['text']]],
        ], $messages);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $parts = $this->client->generate($contents, $this->systemPrompt(), AssistantTools::declarations());

            $calls = array_values(array_filter($parts, fn (array $part) => isset($part['functionCall'])));

            if ($calls === []) {
                $text = trim(implode("\n", array_filter(array_map(
                    fn (array $part) => $part['text'] ?? '',
                    $parts,
                ))));

                return $text !== '' ? $text : 'Не понял — попробуйте переформулировать.';
            }

            // Сначала ответ модели с вызовами, затем результаты инструментов.
            $contents[] = ['role' => 'model', 'parts' => $parts];

            $responses = [];
            foreach ($calls as $part) {
                $call = $part['functionCall'];
                $responses[] = [
                    'functionResponse' => [
                        'name' => $call['name'],
                        'response' => $this->tools->call($call['name'], $call['args'] ?? []),
                    ],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $responses];
        }

        return 'Запутался в запросе — позвоните нам, менеджер поможет: '.$this->phones();
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Ты — помощник магазина автостёкол «'.settings()->brandName().'», город '.currentCity().'.',
            'Помогаешь подобрать стекло и оформить заявку прямо в чате — от машины до заказа, без каталога.',
            '',
            'Правила:',
            '- Веди по шагам: марка, модель и год → тип стекла → search_products → как получит (сервис, выезд, доставка, самовывоз) → имя, телефон, согласие → create_order.',
            '- Отвечай кратко, по-русски, 1–3 предложения. Без markdown-таблиц.',
            '- Цену и наличие называй только из результата search_products. Ничего не выдумывай.',
            '- Если вариантов несколько — предложи 2–3 с ценами и спроси, какой подходит.',
            '- Перед оформлением спроси имя, телефон и согласие на обработку персональных данных. Без согласия create_order не вызывай.',
            '- После заявки назови её номер. Оплату картой в чате не проводишь — менеджер подтвердит наличие и оплату.',
            '- Просят человека или тема не про стёкла — предложи позвонить: '.$this->phones().'.',
            '- Сегодня '.now()->format('d.m.Y').'.',
        ]);
    }

    private function phones(): string
    {
        return collect(settings()->phones())->pluck('label')->implode(', ');
    }
}
