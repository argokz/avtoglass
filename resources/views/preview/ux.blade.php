<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>AvtoGlass — UX preview</title>
    @include('partials.theme-init')
    {!! шрифты() !!}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="storefront">
    <livewire:preview.purchase-journey />
</body>
</html>
