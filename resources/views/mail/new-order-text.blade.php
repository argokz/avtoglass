Новая заявка {{ $order->number }} — {{ $brand }}

Перезвоните в течение 15 минут.

{{ $order->whatsappText() }}

@if ($adminUrl)
Открыть заявку: {{ $adminUrl }}
@endif
