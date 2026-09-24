<?php

namespace App\Livewire;

use App\Ai\AssistantDialog;
use App\Ai\Gemini\GeminiUnavailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Чат с AI-помощником. На главной — весь экран, в каталоге — кнопка слева.
 *
 * История в сессии: если страница всё же перезагрузится, диалог не пропадёт.
 * В базу не кладём. Частота на сессию — чтобы чат не стал прокси к API.
 */
class AiChat extends Component
{
    public const SESSION_KEY = 'ai_chat.messages';

    public bool $open = false;

    /** Полноэкранный чат на главной — без плавающей кнопки. */
    public bool $embedded = false;

    public string $draft = '';

    /** @var list<array{role:string,text:string}> */
    public array $messages = [];

    public function mount(bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $this->open = $embedded;
        $this->messages = $this->storedMessages();
    }

    public function send(AssistantDialog $dialog): void
    {
        $text = trim($this->draft);

        if ($text === '' || mb_strlen($text) > 1000) {
            return;
        }

        $key = 'ai-chat:'.session()->getId();
        $limit = (int) config('ai.rate_limit_per_minute', 20);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $this->messages[] = ['role' => 'model', 'text' => 'Слишком много сообщений подряд — подождите минуту.'];
            $this->draft = '';
            $this->remember();

            return;
        }

        RateLimiter::hit($key, 60);

        $this->messages[] = ['role' => 'user', 'text' => $text];
        $this->draft = '';
        $this->remember();

        try {
            $reply = $dialog->reply($this->messages);
        } catch (GeminiUnavailableException) {
            $reply = 'Помощник сейчас на паузе — лимит ИИ исчерпан на всех моделях. Подберите стекло в каталоге или позвоните нам.';
        } catch (\Throwable $e) {
            report($e);
            $reply = 'Не получилось ответить. Напишите ещё раз или подберите стекло в каталоге — либо позвоните нам.';
        }

        $this->messages[] = ['role' => 'model', 'text' => $reply];
        $this->remember();
    }

    public function render(): View
    {
        return view('livewire.ai-chat');
    }

    /** @return list<array{role:string,text:string}> */
    private function storedMessages(): array
    {
        $stored = session(self::SESSION_KEY);

        if (! is_array($stored) || $stored === []) {
            return $this->greeting();
        }

        $clean = [];

        foreach ($stored as $row) {
            if (! is_array($row) || ! in_array($row['role'] ?? null, ['user', 'model'], true) || ! isset($row['text'])) {
                continue;
            }

            $clean[] = ['role' => $row['role'], 'text' => (string) $row['text']];
        }

        return $clean !== [] ? $clean : $this->greeting();
    }

    /** @return list<array{role:string,text:string}> */
    private function greeting(): array
    {
        return [[
            'role' => 'model',
            'text' => 'Здравствуйте! Помогу подобрать стекло и оформить заявку. Напишите марку и модель авто или еврокод.',
        ]];
    }

    private function remember(): void
    {
        $messages = array_values(array_filter(
            $this->messages,
            fn ($row) => is_array($row)
                && in_array($row['role'] ?? null, ['user', 'model'], true)
                && isset($row['text']),
        ));

        if (count($messages) > 40) {
            $messages = array_slice($messages, -40);
        }

        $this->messages = $messages;
        session([self::SESSION_KEY => $messages]);
    }
}
