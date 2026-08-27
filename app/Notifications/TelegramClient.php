<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка в Telegram Bot API.
 *
 * Отдельный класс, а не вызов Http внутри задачи: канал уведомлений
 * когда-нибудь сменится (WhatsApp Business, SMS-шлюз), и менять придётся
 * одно место, а не каждое сообщение.
 */
class TelegramClient
{
    /** Настроен ли канал вообще: без токена и чата слать некуда. */
    public function configured(): bool
    {
        return (bool) config('notifications.telegram.enabled')
            && filled(config('notifications.telegram.token'))
            && filled(config('notifications.telegram.chat_id'));
    }

    /**
     * @param  string  $text  разметка HTML: <b>, <i>, <a href>, <code>
     */
    public function send(string $text): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $token = config('notifications.telegram.token');

        try {
            $response = Http::timeout((int) config('notifications.telegram.timeout'))
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => config('notifications.telegram.chat_id'),
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    // Ссылка на заявку не должна разворачиваться в карточку:
                    // в чате смены важен текст, а не превью админки.
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram недоступен', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Telegram отклонил сообщение', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }
}
