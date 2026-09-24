<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Новая заявка {{ $order->number }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#1a1d24;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f5;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e4e4e7;border-radius:12px;">
                    <tr>
                        <td style="padding:20px 24px 8px;font-size:13px;color:#71717a;">
                            {{ $brand }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 16px;font-size:22px;font-weight:700;line-height:1.25;">
                            Новая заявка {{ $order->number }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 16px;font-size:15px;line-height:1.5;">
                            Перезвоните в течение 15 минут — в этой нише клиент пишет сразу в несколько мест.
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 20px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e7;border-radius:8px;">
                                <tr>
                                    <td style="padding:12px 16px;font-size:13px;color:#71717a;width:36%;">Клиент</td>
                                    <td style="padding:12px 16px;font-size:15px;font-weight:700;">
                                        {{ $order->customer?->name }}
                                        @if ($order->customer?->phone)
                                            <br>
                                            <a href="tel:{{ $order->customer->phone }}" style="color:#b42318;text-decoration:none;">
                                                {{ $order->customer->phone }}
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                                @if ($order->fulfillment)
                                    <tr>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;">Нужно</td>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:15px;">{{ $order->fulfillment->label() }}</td>
                                    </tr>
                                @endif
                                @if (filled($order->address) || filled($order->city))
                                    <tr>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;">Адрес</td>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:15px;">
                                            {{ filled($order->address) ? $order->address : '' }}
                                            @if (filled($order->city))
                                                {{ filled($order->address) ? ', ' : '' }}{{ $order->city }}
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                                @if ($order->payment_method)
                                    <tr>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;">Оплата</td>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:15px;">{{ $order->payment_method->label() }}</td>
                                    </tr>
                                @endif
                                @if ($order->items->isNotEmpty())
                                    <tr>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;vertical-align:top;">Позиции</td>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:15px;">
                                            @foreach ($order->items as $item)
                                                {{ $item->name }}
                                                @if ((int) $item->quantity > 1)
                                                    × {{ $item->quantity }}
                                                @endif
                                                @if (! $loop->last)
                                                    <br>
                                                @endif
                                            @endforeach
                                        </td>
                                    </tr>
                                @endif
                                @if ($order->total_amount !== null)
                                    <tr>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;">Сумма</td>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:18px;font-weight:700;">
                                            {{ number_format((float) $order->total_amount, 0, ',', ' ') }} ₸
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;">Откуда</td>
                                    <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:15px;">{{ $order->source->label() }}</td>
                                </tr>
                                @if (filled($order->comment))
                                    <tr>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;vertical-align:top;">Комментарий</td>
                                        <td style="padding:12px 16px;border-top:1px solid #e4e4e7;font-size:15px;white-space:pre-wrap;">{{ $order->comment }}</td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>
                    @if ($adminUrl)
                        <tr>
                            <td style="padding:0 24px 24px;">
                                <a href="{{ $adminUrl }}"
                                   style="display:inline-block;background:#b42318;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 18px;border-radius:8px;">
                                    Открыть заявку
                                </a>
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
