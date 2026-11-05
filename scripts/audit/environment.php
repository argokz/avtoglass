<?php

declare(strict_types=1);
use App\Models\CarModel;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('SELECT current_database() AS name');
$report = [
    'environment' => app()->environment(),
    'database' => $identity->name,
    'php' => PHP_VERSION,
    'laravel' => app()->version(),
    'ordering' => config('ordering'),
    'ai' => [
        'mode' => config('ai.mode'),
        'models' => config('ai.models'),
        'local_model' => config('ai.litellm.model'),
        'gemini_key_configured' => count(config('ai.keys', [])) > 0,
        'local_key_configured' => filled(config('ai.litellm.key')),
        'queue' => config('ai.queue_name'),
    ],
    'settings' => collect(['cart', 'ai_assistant', 'payments', 'reservations'])->mapWithKeys(fn (string $name): array => [$name => settings()->enabled($name)])->all(),
    'counts' => [
        'products' => Product::count(),
        'offers' => Offer::count(),
        'car_models' => CarModel::count(),
    ],
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
