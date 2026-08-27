<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Документ')</title>
    <style>
        /*
         * Документы печатают и подшивают, поэтому вёрстка отдельная от сайта:
         * никакой тёмной темы, фиксированный А4, чёрным по белому.
         */
        @page { size: A4; margin: 12mm 14mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font: 12px/1.45 "DejaVu Sans", Arial, Helvetica, sans-serif;
            color: #000;
            background: #f4f4f5;
        }

        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 12px auto;
            padding: 12mm 14mm;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }

        h1 { margin: 0 0 4px; font-size: 17px; }
        h2 { margin: 18px 0 6px; font-size: 13px; }

        .muted { color: #555; }
        .small { font-size: 11px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: 700; }

        .head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
        }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #999; padding: 5px 6px; vertical-align: top; }
        th { background: #eee; font-size: 11px; text-align: left; }
        td.num, th.num { text-align: right; white-space: nowrap; }

        .totals { margin-top: 10px; }
        .totals td { border: none; padding: 2px 0; }

        .sign { margin-top: 26px; display: flex; gap: 40px; }
        .sign > div { flex: 1; }
        .sign .line { margin-top: 26px; border-top: 1px solid #000; padding-top: 3px; }

        .note {
            margin-top: 14px;
            padding: 8px 10px;
            border-left: 3px solid #999;
            background: #f7f7f7;
            font-size: 11px;
        }

        .warn { border-left-color: #b45309; background: #fff7ed; }

        .toolbar {
            width: 210mm; margin: 12px auto 0; display: flex; gap: 8px; justify-content: flex-end;
        }
        .toolbar button, .toolbar a {
            font: inherit; padding: 7px 14px; border: 1px solid #999; border-radius: 6px;
            background: #fff; cursor: pointer; text-decoration: none; color: #000;
        }

        @media print {
            body { background: #fff; }
            .sheet { margin: 0; padding: 0; box-shadow: none; width: auto; min-height: 0; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a href="{{ url()->previous() }}">← Назад</a>
    <button onclick="window.print()">Печать / Сохранить в PDF</button>
</div>

<div class="sheet">
    <div class="head">
        <div>
            <div class="bold">{{ config('company.requisites.legal_name') }}</div>
            <div class="small muted">
                {{ config('company.address') }}<br>
                @foreach (config('company.phones') as $phone){{ $phone['display'] }}@if (! $loop->last), @endif @endforeach<br>
                {{ config('company.email') }}
                @if (config('company.requisites.bin'))
                    <br>БИН {{ config('company.requisites.bin') }}
                @endif
            </div>
        </div>
        <div class="right">
            <h1>@yield('doc-title')</h1>
            <div class="small">
                № <span class="bold">{{ $document->number }}</span><br>
                от {{ $document->issued_on->format('d.m.Y') }}
            </div>
        </div>
    </div>

    @yield('content')

    @unless (config('company.requisites.bin'))
        {{-- Явное предупреждение: без реквизитов документ ничего не подтверждает --}}
        <div class="note warn">
            Реквизиты организации не заполнены (БИН, банк, ИИК). До их внесения
            документ можно использовать только как внутренний — юридической силы он не имеет.
            Заполняются в <code>config/company.php</code> или через переменные окружения.
        </div>
    @endunless
</div>

</body>
</html>
