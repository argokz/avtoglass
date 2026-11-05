<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Исходящий WhatsApp через Green-API.
 *
 * send() — сигнал о заявке в чат смены. sendTo() — сообщение клиенту, и
 * только такое, которое он сам включил (напоминание о регулярной заявке).
 * Клиентский wa.me на странице «спасибо» — отдельная история: там человек
 * сам жмёт «отправить». Без инстанса никуда не ходим: на dev это был бы
 * шум, а заявка и так уже принята.
 */
class WhatsAppClient
{
    public function configured(): bool
    {
        return $this->canSend() && filled($this->chatId());
    }

    /** Инстанс есть — можно писать клиентам, даже если чат смены не задан. */
    public function canSend(): bool
    {
        return filled(config('notifications.whatsapp.green_instance'))
            && filled(config('notifications.whatsapp.green_token'));
    }

    public function send(string $text): bool
    {
        return $this->configured() && $this->post($this->chatId(), $text);
    }

    public function sendTo(?string $phone, string $text): bool
    {
        $digits = Customer::normalizePhone($phone);

        return $this->canSend() && $digits !== null && $this->post($digits.'@c.us', $text);
    }

    private function post(string $chatId, string $text): bool
    {
        $instance = config('notifications.whatsapp.green_instance');
        $token = config('notifications.whatsapp.green_token');
        $url = "https://api.green-api.com/waInstance{$instance}/sendMessage/{$token}";

        try {
            $response = Http::timeout((int) config('notifications.whatsapp.timeout', 8))
                ->post($url, [
                    'chatId' => $chatId,
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
