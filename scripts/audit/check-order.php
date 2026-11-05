<?php

declare(strict_types=1);
use App\Models\Order;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (in_array('--isolated', $argv, true)) {
    foreach (['DB_PORT' => '5441', 'DB_USERNAME' => 'audit', 'DB_PASSWORD' => ''] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('preview') || DB::selectOne('SELECT current_database() AS name')->name !== 'avtoglasskz_modernization_preview') {
    throw new RuntimeException('Order verification requires isolated preview');
}
$result = json_decode(file_get_contents(base_path('output/playwright/audit-2026-10-09/checkout-repeat.json')), true);
$orders = Order::where('form_token', $result['formToken'])->get();
$result['orders'] = $orders->count();
$result['order_sources'] = $orders->map(fn ($order) => $order->source->value)->all();
$result['outbox_events'] = DB::table('outbox_events')->whereIn('aggregate_id', $orders->pluck('id')->map(fn ($id) => (string) $id))->count();
file_put_contents(base_path('output/playwright/audit-2026-10-09/checkout-repeat.json'), json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result), PHP_EOL;
