<?php

namespace App\Livewire\Preview;

/** Synthetic conversations. This store never calls an AI provider or application tools. */
final class PreviewChatBook
{
    public function read(): array
    {
        return session($this->key(), ['current' => null, 'dialogs' => []]);
    }

    public function save(array $book): void
    {
        session()->put($this->key(), $book);
    }

    public function adoptGuest(): void
    {
        $guestKey = $this->key('guest');
        $guest = session($guestKey, ['dialogs' => []]);
        $book = $this->read();
        foreach ($guest['dialogs'] as $id => $dialog) {
            if (count($dialog['messages']) > 1) {
                $book['dialogs'][$id] = $dialog;
                if ($id === ($guest['current'] ?? null)) {
                    $book['current'] = $id;
                }
            }
        }
        $this->save($book);
        session()->forget($guestKey);
    }

    private function key(?string $owner = null): string
    {
        return 'modernization.preview_conversations.'.hash('sha256', $owner ?? (new PreviewOrderBook)->owner());
    }
}
