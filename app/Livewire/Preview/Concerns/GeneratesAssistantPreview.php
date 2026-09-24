<?php

namespace App\Livewire\Preview\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Deterministic dialogue fixtures for UX checks; never invokes the existing AI tools. */
trait GeneratesAssistantPreview
{
    public string $chatInput = '';

    public string $chatScenario = 'ready';

    public function sendChatMessage(): void
    {
        $this->useLocale();
        $this->chatInput = trim($this->chatInput);
        $this->validate(['chatInput' => ['required', 'string', 'max:1000']], ['chatInput.*' => __('shop.chat_input_invalid')]);
        if (! $this->chatDialogId) {
            $input = $this->chatInput;
            $this->newChatDialog();
            $this->chatInput = $input;
        }
        $dialog = $this->currentChatDialog();
        $this->assertChatCanReply($dialog);
        if (count($dialog['messages']) === 1) {
            $dialog['title'] = mb_substr($this->chatInput, 0, 60);
            $this->chatTitle = $dialog['title'];
        }
        $dialog['messages'][] = ['id' => (string) Str::uuid(), 'role' => 'user', 'text' => $this->chatInput, 'status' => 'complete', 'type' => 'text'];
        $this->beginChatReply($dialog);
        $this->chatInput = '';
        $this->chatCheckoutOpen = false;
    }

    private function assertChatCanReply(array $dialog): void
    {
        if ($dialog['archived'] || ($dialog['generation']['status'] ?? null) === 'running' || count($dialog['messages']) >= 78) {
            throw ValidationException::withMessages(['chat' => __('shop.chat_reply_blocked')]);
        }
    }

    private function beginChatReply(array $dialog, ?string $choice = null): void
    {
        $plan = $this->assistantReplyPlan($dialog, $choice);
        $messageId = (string) Str::uuid();
        $dialog['messages'][] = ['id' => $messageId, 'role' => 'assistant', 'text' => '', 'status' => 'running', 'type' => 'text'];
        $dialog['generation'] = ['id' => (string) Str::uuid(), 'messageId' => $messageId, 'step' => 0, 'status' => 'running',
            'scenario' => in_array($this->chatScenario, ['ready', 'slow', 'error'], true) ? $this->chatScenario : 'ready',
            'plan' => $plan, 'expires' => now()->addMinutes(2)->timestamp];
        $this->saveChatDialog($dialog);
        $this->resetValidation();
        $this->dispatch('assistant-scroll');
    }

    public function advanceChatReply(string $generationId): void
    {
        $dialog = $this->currentChatDialog();
        $generation = $dialog['generation'];
        if (! $generation || $generation['id'] !== $generationId || $generation['status'] !== 'running' || $dialog['archived']) {
            $this->syncChatDialog($dialog);

            return;
        }
        $step = ++$generation['step'];
        $steps = $generation['scenario'] === 'slow' ? 8 : 3;
        $status = $generation['expires'] < now()->timestamp || $generation['scenario'] === 'error' ? 'error' : ($step >= $steps ? 'complete' : 'running');
        foreach ($dialog['messages'] as &$message) {
            if ($message['id'] === $generation['messageId']) {
                $message['text'] = mb_substr($generation['plan']['text'], 0, (int) ceil(mb_strlen($generation['plan']['text']) * min($step, $steps) / $steps));
                $message['status'] = $status;
                if ($status === 'complete') {
                    $message = array_replace($message, $generation['plan']);
                }
            }
        }
        unset($message);
        $generation['status'] = $status;
        $dialog['generation'] = $generation;
        $this->saveChatDialog($dialog);
        $this->dispatch('assistant-scroll');
    }

    public function stopChatReply(string $generationId): void
    {
        $dialog = $this->currentChatDialog();
        if (($dialog['generation']['id'] ?? null) !== $generationId || $dialog['generation']['status'] !== 'running') {
            $this->syncChatDialog($dialog);

            return;
        }
        $dialog['generation']['status'] = 'cancelled';
        foreach ($dialog['messages'] as &$message) {
            if ($message['id'] === $dialog['generation']['messageId']) {
                $message['status'] = 'cancelled';
            }
        }
        unset($message);
        $this->saveChatDialog($dialog);
    }

    public function retryChatReply(string $generationId): void
    {
        $this->useLocale();
        $dialog = $this->currentChatDialog();
        abort_unless(($dialog['generation']['id'] ?? null) === $generationId && in_array($dialog['generation']['status'], ['error', 'cancelled'], true), 409);
        $this->assertChatCanReply($dialog);
        $plan = $dialog['generation']['plan'];
        // Reuse the prepared reply; a retry must not append the user's request twice.
        $id = (string) Str::uuid();
        $dialog['messages'][] = ['id' => $id, 'role' => 'assistant', 'text' => '', 'status' => 'running', 'type' => 'text'];
        $dialog['generation'] = ['id' => (string) Str::uuid(), 'messageId' => $id, 'step' => 0, 'status' => 'running', 'scenario' => 'ready', 'plan' => $plan, 'expires' => now()->addMinutes(2)->timestamp];
        $this->chatScenario = 'ready';
        $this->saveChatDialog($dialog);
    }

    public function answerChatChoice(string $messageId, string $choice): void
    {
        $this->useLocale();
        $dialog = $this->currentChatDialog();
        $this->assertChatCanReply($dialog);
        $message = $this->activeChatCard($messageId);
        abort_unless(isset($message['choices'][$choice]), 422);
        $dialog['messages'][] = ['id' => (string) Str::uuid(), 'role' => 'user', 'text' => __('shop.'.$message['choices'][$choice]), 'status' => 'complete', 'type' => 'text'];
        $this->beginChatReply($dialog, $choice);
    }

    private function assistantReplyPlan(array &$dialog, ?string $choice): array
    {
        $text = mb_strtolower($dialog['messages'][array_key_last($dialog['messages'])]['text']);
        if ($choice === 'car') {
            $dialog['context']['car'] = 'camry70';
        }
        if (in_array($choice, ['camera_no', 'camera_yes', 'camera_unknown'], true)) {
            $dialog['context']['camera'] = $choice;
        }
        if ($choice && str_starts_with($choice, 'increase:')) {
            return $this->chatQuantityPlan((int) substr($choice, 9));
        }
        if ($choice === 'refresh' || preg_match('/сравн|салыстыр|demo-[1-4]/u', $text) || str_starts_with($choice ?? '', 'camera_')) {
            $ids = match ($dialog['context']['camera'] ?? null) {
                'camera_yes' => [2], 'camera_no' => [1, 4], default => [1, 2, 4]
            };
            if (preg_match('/demo-([1-4])/u', $text, $match) && ! $choice) {
                $ids = [(int) $match[1]];
            }
            $uncertain = ($dialog['context']['car'] ?? null) !== 'camry70' || ! in_array($dialog['context']['camera'] ?? null, ['camera_no', 'camera_yes'], true);
            $offers = array_intersect_key($this->offers(), array_flip($ids));
            if ($uncertain) {
                foreach ($offers as &$offer) {
                    $offer['verified'] = false;
                }
                unset($offer);
            }

            return ['text' => __('shop.'.($uncertain ? 'chat_offers_uncertain' : 'chat_offers_reply')), 'type' => 'offers',
                'offers' => $offers, 'catalog' => $this->chatCatalogFingerprint(), 'mode' => $this->mode, 'uncertain' => $uncertain];
        }
        if (preg_match('/повтор|истори|заказ.*статус|тапсырыстар|қайтал/u', $text)) {
            return ['text' => __('shop.chat_history_reply'), 'type' => 'history'];
        }
        if (preg_match('/менеджер|человек|оператор|адам/u', $text)) {
            return ['text' => __('shop.chat_handoff_reply'), 'type' => 'handoff'];
        }
        if (preg_match('/добав.*(два|2)|тағы.*(екі|2)/u', $text)) {
            if (count($this->cart) === 1) {
                return $this->chatQuantityPlan((int) array_key_first($this->cart));
            }
            if (count($this->cart) > 1) {
                $choices = [];
                foreach ($this->cart as $id => $quantity) {
                    $choices['increase:'.$id] = 'chat_increase_'.$id;
                }

                return ['text' => __('shop.chat_which_item'), 'type' => 'choice', 'choices' => $choices];
            }
        }
        if (($choice === 'quote' || preg_match('/оформ|заказ|купить|адрес|тапсырыс|мекенжай/u', $text)) && $this->cart) {
            return ['text' => __('shop.chat_quote_reply'), 'type' => 'quote', 'quote' => $this->quote(), 'draft' => $this->chatDraftFingerprint()];
        }
        if (($dialog['context']['car'] ?? null) === 'camry70') {
            return ['text' => __('shop.chat_camera_question'), 'type' => 'choice', 'choices' => ['camera_no' => 'chat_camera_no', 'camera_yes' => 'chat_camera_yes', 'camera_unknown' => 'chat_camera_unknown']];
        }

        return ['text' => __('shop.chat_car_question'), 'type' => 'choice', 'choices' => ['car' => 'chat_car_confirm']];
    }

    private function chatQuantityPlan(int $offerId): array
    {
        $quantity = $this->validQuantity($this->cart[$offerId] ?? null) ? (int) $this->cart[$offerId] + 2 : 0;
        if (! isset($this->cart[$offerId]) || ! $this->validQuantity($quantity)) {
            return ['text' => __('shop.fix_quantity'), 'type' => 'text'];
        }

        return ['text' => __('shop.chat_quantity_reply', ['article' => $this->offers()[$offerId]['article'], 'quantity' => $quantity]),
            'type' => 'quantity', 'offerId' => $offerId, 'quantity' => $quantity, 'draft' => $this->chatDraftFingerprint()];
    }
}
