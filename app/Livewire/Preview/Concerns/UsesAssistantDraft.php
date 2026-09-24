<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewOrderBook;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait UsesAssistantDraft
{
    #[Locked]
    public bool $chatCheckoutOpen = false;

    #[Locked]
    public array $assistantReviewIds = [];

    public function chatCatalogFingerprint(): string
    {
        return hash('sha256', json_encode([$this->mode, $this->offers()], JSON_THROW_ON_ERROR));
    }

    public function chatDraftFingerprint(): string
    {
        return hash('sha256', json_encode([$this->chatCatalogFingerprint(), $this->cart, $this->unresolvedRows, $this->fulfillment, $this->address, $this->assistantReviewIds], JSON_THROW_ON_ERROR));
    }

    public function chatCardIsCurrent(array $message): bool
    {
        return ($message['status'] ?? null) === 'complete'
            && $message['id'] === ($this->chatMessages[array_key_last($this->chatMessages)]['id'] ?? null)
            && (! isset($message['catalog']) || $message['catalog'] === $this->chatCatalogFingerprint())
            && (! isset($message['draft']) || $message['draft'] === $this->chatDraftFingerprint());
    }

    private function activeChatCard(string $messageId): array
    {
        $this->useLocale();
        $dialog = $this->currentChatDialog();
        $this->syncChatDialog($dialog);
        $message = $dialog['messages'][array_key_last($dialog['messages'])];
        if ($dialog['archived'] || $message['id'] !== $messageId || ! $this->chatCardIsCurrent($message)) {
            throw ValidationException::withMessages(['chat' => __('shop.chat_card_stale')]);
        }

        return $message;
    }

    public function chooseChatOffer(string $messageId, int $id): void
    {
        $message = $this->activeChatCard($messageId);
        abort_unless($message['type'] === 'offers' && isset($message['offers'][$id], $this->offers()[$id]), 422);
        $this->cart[$id] ??= 1;
        if ($message['uncertain']) {
            $this->assistantReviewIds[$id] = true;
        } else {
            unset($this->assistantReviewIds[$id]);
        }
        $this->consent = $this->accepted = false;
        $this->chatCheckoutOpen = false;
        $this->saveDraft();
    }

    public function applyChatQuantity(string $messageId): void
    {
        $message = $this->activeChatCard($messageId);
        abort_unless($message['type'] === 'quantity' && isset($this->cart[$message['offerId']]) && $this->validQuantity($message['quantity']), 422);
        $this->cart[$message['offerId']] = $message['quantity'];
        $this->consent = $this->accepted = false;
        $this->saveDraft();
    }

    public function refreshChatCards(string $messageId): void
    {
        $this->useLocale();
        $dialog = $this->currentChatDialog();
        $this->assertChatCanReply($dialog);
        $message = collect($dialog['messages'])->firstWhere('id', $messageId);
        abort_unless($message && in_array($message['type'], ['offers', 'quote', 'quantity'], true), 422);
        $dialog['messages'][] = ['id' => (string) Str::uuid(), 'role' => 'user', 'text' => __('shop.chat_refresh'), 'status' => 'complete', 'type' => 'text'];
        $this->beginChatReply($dialog, $message['type'] === 'offers' ? 'refresh' : 'quote');
    }

    public function prepareChatCheckout(): void
    {
        $this->useLocale();
        $dialog = $this->currentChatDialog();
        abort_unless(! $dialog['archived'], 409);
        if (! $this->cart && ! $this->unresolvedRows) {
            throw ValidationException::withMessages(['chat' => __('shop.empty_title')]);
        }
        if (($dialog['generation']['status'] ?? null) === 'running') {
            $this->stopChatReply($dialog['generation']['id']);
        }
        $this->consent = $this->accepted = false;
        $this->chatCheckoutOpen = true;
        $this->resetValidation();
        $this->dispatch('assistant-checkout-ready');
    }

    public function closeChatCheckout(): void
    {
        $this->chatCheckoutOpen = false;
        $this->consent = false;
        $this->dispatch('assistant-focus');
    }

    private function recordChatOrder(): void
    {
        $dialog = $this->currentChatDialog();
        $dialog['messages'][] = ['id' => (string) Str::uuid(), 'role' => 'assistant', 'text' => __('shop.chat_order_created'), 'status' => 'complete', 'type' => 'order',
            'order' => array_intersect_key($this->demoOrder, array_flip(['number', 'total', 'status', 'version']))];
        $this->saveChatDialog($dialog);
        $this->chatCheckoutOpen = false;
        $this->dispatch('assistant-scroll');
    }

    public function openChatOrder(string $messageId): void
    {
        $message = collect($this->currentChatDialog()['messages'])->firstWhere('id', $messageId);
        abort_unless($message && $message['type'] === 'order', 404);
        $order = (new PreviewOrderBook)->find($message['order']['number']);
        abort_unless($order, 404);
        $this->demoOrder = $order;
        $this->proposal = $order['proposal'] ?? null;
        $this->proposalSequence = $this->proposal['version'] ?? 0;
        $this->go('order');
        $this->historyOrder = $this->signedIn() ? $order['number'] : '';
    }

    public function requestChatManager(string $messageId): void
    {
        $message = $this->activeChatCard($messageId);
        abort_unless($message['type'] === 'handoff', 422);
        $dialog = $this->currentChatDialog();
        $reference = 'DEMO-CHAT-'.strtoupper(substr((string) Str::uuid(), 0, 8));
        $dialog['handoffs'][] = ['reference' => $reference, 'summary' => mb_substr(implode(' / ', array_column(array_filter($dialog['messages'], fn ($row) => $row['role'] === 'user'), 'text')), 0, 500)];
        $dialog['messages'][] = ['id' => (string) Str::uuid(), 'role' => 'assistant', 'text' => __('shop.chat_handoff_saved', ['number' => $reference]), 'status' => 'complete', 'type' => 'text'];
        $this->saveChatDialog($dialog);
    }

    public function setChatMode(string $mode): void
    {
        abort_unless(in_array($mode, ['retail', 'wholesale'], true), 422);
        $this->switchMode($mode);
        $this->chatCheckoutOpen = false;
        $this->consent = $this->accepted = false;
        $this->saveDraft();
    }

    public function leaveAssistant(): void
    {
        $this->go($this->mode === 'wholesale' ? 'wholesale' : 'catalog');
    }
}
