<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\FieldPanelProvider;
use App\Providers\PaymentServiceProvider;

return [
    App\Providers\PreviewServiceProvider::class,
    AppServiceProvider::class,
    PaymentServiceProvider::class,
    AdminPanelProvider::class,
    FieldPanelProvider::class,
];
