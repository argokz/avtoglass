<?php

namespace App\Notifications;

use App\Mail\NewOrderAlert;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Письмо смене о новой заявке.
 *
 * Адрес берём из настроек компании — это та же почта, что в подвале сайта.
 * ORDER_ALERT_EMAIL в .env подменяет её, если письма должны уходить
 * не на витринный ящик, а в общий ящик смены.
 *
 * Ошибки SMTP глотаем: заявка уже принята, падать из-за почты нельзя.
 */
class EmailClient
{
    public function configured(): bool
    {
        return settings()->enabled('email_alerts')
            && $this->recipients() !== [];
    }

    public function send(Order $order, ?string $adminUrl = null): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            Mail::to($this->recipients())->send(new NewOrderAlert($order, $adminUrl));
        } catch (\Throwable $e) {
            Log::warning('Почта недоступна', ['error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $raw = (string) (config('notifications.email.to') ?: settings()->email());

        return collect(preg_split('/[,\s]+/', $raw) ?: [])
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }
}
