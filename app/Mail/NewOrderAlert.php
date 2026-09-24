<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо смене о новой заявке.
 *
 * Очередь уже есть у AnnounceNewOrder: здесь не ShouldQueue, иначе заявка
 * ждала бы двух воркеров, а письмо уехало бы без номера, если первая
 * задача ещё не закоммитилась.
 */
class NewOrderAlert extends Mailable
{
    public function __construct(
        public Order $order,
        public ?string $adminUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая заявка '.$this->order->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.new-order',
            text: 'mail.new-order-text',
            with: [
                'order' => $this->order,
                'adminUrl' => $this->adminUrl,
                'brand' => settings()->brandName(),
            ],
        );
    }
}
