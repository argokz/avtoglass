<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сигнал о заявке в WhatsApp смены.
 *
 * Клиентский wa.me на странице «спасибо» — отдельная история: там человек
 * сам жмёт «отправить». Здесь — исходящее сообщение в чат менеджера,
 * если настроен Green-API. Без инстанса никуда не ходим: на dev это
 * был бы шум, а заявка и так уже принята.
 */
class WhatsAppClient
{
    public function configured(): bool
    {
        return filled(config('notifications.whatsapp.green_instance'))
            && filled(config('notifications.whatsapp.green_token'))
            && filled($this->chatId());
    }

    public function send(string $text): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $instance = config('notifications.whatsapp.green_instance');
        $token = config('notifications.whatsapp.green_token');
        $url = "https://api.green-api.com/waInstance{$instance}/sendMessage/{$token}";

        try {
            $response = Http::timeout((int) config('notifications.whatsapp.timeout', 8))
                ->post($url, [
                    'chatId' => $this->chatId(),
                    'message' => $text,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp недоступен', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('WhatsApp отклонил сообщение', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }

    private function chatId(): ?string
    {
        $raw = preg_replace('/\D+/', '', (string) config('notifications.whatsapp.chat_id'))
            ?: preg_replace('/\D+/', '', (string) settings()->whatsapp());

        if ($raw === null || $raw === '') {
            return null;
        }

        return $raw.'@c.us';
    }
}
