<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewChatBook;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait BrowsesAssistantDialogs
{
    #[Locked]
    public ?string $chatDialogId = null;

    #[Locked]
    public array $chatMessages = [];

    #[Locked]
    public ?array $chatGeneration = null;

    #[Locked]
    public array $chatContext = [];

    public string $chatSearch = '';

    public string $chatTitle = '';

    public bool $chatShowArchived = false;

    public function chatDialogs(): array
    {
        $query = mb_strtolower(trim(mb_substr($this->chatSearch, 0, 100)));

        return array_reverse(array_filter((new PreviewChatBook)->read()['dialogs'], function ($dialog) use ($query) {
            return $dialog['archived'] === $this->chatShowArchived
                && ($query === '' || str_contains(mb_strtolower($dialog['title'].' '.implode(' ', array_column($dialog['messages'], 'text'))), $query));
        }), true);
    }

    private function mountAssistant(): void
    {
        $this->reset('chatDialogId', 'chatMessages', 'chatGeneration', 'chatContext', 'chatInput', 'chatTitle', 'chatCheckoutOpen');
        $book = (new PreviewChatBook)->read();
        if ($book['current'] && isset($book['dialogs'][$book['current']])) {
            $this->loadChatDialog($book['current']);
        } elseif ($this->screen === 'chat' && ! $book['dialogs']) {
            $this->newChatDialog();
        }
    }

    public function newChatDialog(): void
    {
        $this->useLocale();
        $book = (new PreviewChatBook)->read();
        if (count($book['dialogs']) >= 20) {
            throw ValidationException::withMessages(['chat' => __('shop.chat_dialog_limit')]);
        }
        $id = (string) Str::uuid();
        $book['dialogs'][$id] = ['id' => $id, 'title' => __('shop.chat_new'), 'archived' => false, 'context' => [], 'generation' => null,
            'messages' => [['id' => (string) Str::uuid(), 'role' => 'assistant', 'text' => __('shop.chat_greeting'), 'status' => 'complete', 'type' => 'text']],
        ];
        $book['current'] = $id;
        (new PreviewChatBook)->save($book);
        $this->chatShowArchived = false;
        $this->chatSearch = '';
        $this->loadChatDialog($id);
    }

    public function loadChatDialog(string $id): void
    {
        $book = (new PreviewChatBook)->read();
        abort_unless(isset($book['dialogs'][$id]), 404);
        $book['current'] = $id;
        (new PreviewChatBook)->save($book);
        $this->chatDialogId = $id;
        $this->syncChatDialog($book['dialogs'][$id]);
        $this->chatTitle = $book['dialogs'][$id]['title'];
        $this->chatInput = '';
        $this->chatCheckoutOpen = false;
        $this->resetValidation();
        $this->dispatch('assistant-focus');
    }

    public function currentChatDialog(): array
    {
        $dialog = (new PreviewChatBook)->read()['dialogs'][$this->chatDialogId] ?? null;
        abort_unless($dialog, 404);

        return $dialog;
    }

    private function saveChatDialog(array $dialog): void
    {
        $book = (new PreviewChatBook)->read();
        abort_unless(isset($book['dialogs'][$dialog['id']]), 404);
        $book['dialogs'][$dialog['id']] = $dialog;
        (new PreviewChatBook)->save($book);
        $this->syncChatDialog($dialog);
    }

    private function syncChatDialog(array $dialog): void
    {
        $this->chatMessages = $dialog['messages'];
        $this->chatGeneration = $dialog['generation'] ? array_diff_key($dialog['generation'], ['plan' => true]) : null;
        $this->chatContext = $dialog['context'];
    }

    public function renameChatDialog(): void
    {
        $this->useLocale();
        $this->chatTitle = trim($this->chatTitle);
        $this->validate(['chatTitle' => ['required', 'string', 'max:80']], ['chatTitle.*' => __('shop.chat_title_invalid')]);
        $dialog = $this->currentChatDialog();
        $dialog['title'] = $this->chatTitle;
        $this->saveChatDialog($dialog);
    }

    public function archiveChatDialog(): void
    {
        $dialog = $this->currentChatDialog();
        if (($dialog['generation']['status'] ?? null) === 'running') {
            $this->stopChatReply($dialog['generation']['id']);
            $dialog = $this->currentChatDialog();
        }
        $dialog['archived'] = true;
        $this->saveChatDialog($dialog);
        $this->chatShowArchived = true;
        $this->chatCheckoutOpen = false;
    }

    public function restoreChatDialog(): void
    {
        $dialog = $this->currentChatDialog();
        $dialog['archived'] = false;
        $this->saveChatDialog($dialog);
        $this->chatShowArchived = false;
    }
}
