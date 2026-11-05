<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
foreach (['APP_ENV' => 'preview', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5441', 'DB_USERNAME' => 'audit', 'DB_PASSWORD' => ''] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne('SELECT current_database() AS name, inet_server_port() AS port');
if (! app()->environment('preview') || $identity->name !== 'avtoglasskz_modernization_preview' || (int) $identity->port !== 5441) {
    throw new RuntimeException('Load fixtures require the dedicated audit PostgreSQL on 5441');
}
if (DB::table('products')->where('slug', 'like', 'audit-load-%')->exists()) {
    throw new RuntimeException('Load fixtures already exist; do not silently duplicate');
}
$product = (array) DB::table('products')->where('slug', 'preview-camry-70')->first();
$offer = (array) DB::table('offers')->where('product_id', $product['id'])->first();
$fitment = (array) DB::table('fitments')->where('product_id', $product['id'])->first();
$model = (array) DB::table('car_models')->where('id', $fitment['car_model_id'])->first();
unset($product['id'], $offer['id'], $fitment['id'], $model['id']);
$before = ['products' => DB::table('products')->count(), 'offers' => DB::table('offers')->count(), 'models' => DB::table('car_models')->count()];
DB::transaction(function () use ($product, $offer, $fitment, $model, $before): void {
    $modelIds = DB::table('car_models')->pluck('id')->all();
    for ($i = $before['models']; $i < 1414; $i++) {
        $row = $model;
        $row['slug'] = 'audit-load-model-'.$i;
        $row['name'] = 'Audit vehicle '.$i;
        $row['image_path'] = null;
        $row['products_count'] = 4;
        $modelIds[] = DB::table('car_models')->insertGetId($row);
    }
    $newProducts = [];
    for ($i = $before['products']; $i < 6030; $i++) {
        $row = $product;
        $row['slug'] = 'audit-load-'.$i;
        $row['signature'] = hash('sha1', $row['slug']);
        $row['eurocode'] = 'AUDIT-LOAD-'.$i;
        $row['name'] = ($i % 10 === 0 ? 'Camry' : 'Audit vehicle').' '.$i.' — синтетическое стекло';
        $row['price_min'] = $row['price_max'] = 30000 + $i;
        $id = DB::table('products')->insertGetId($row);
        $newProducts[] = $id;
        $line = $fitment;
        $line['product_id'] = $id;
        $line['car_model_id'] = $modelIds[$i % count($modelIds)];
        DB::table('fitments')->insert($line);
        $line = $offer;
        $line['product_id'] = $id;
        $line['article'] = 'AUDIT-LOAD-'.$i;
        $line['raw_name'] = $row['name'];
        $line['price_retail'] = 30000 + $i;
        $line['price_wholesale'] = 20000 + $i;
        DB::table('offers')->insert($line);
    }
    $remaining = 6643 - DB::table('offers')->count();
    for ($i = 0; $i < $remaining; $i++) {
        $line = $offer;
        $line['product_id'] = $newProducts[$i % count($newProducts)];
        $line['article'] = 'AUDIT-LOAD-EXTRA-'.$i;
        DB::table('offers')->insert($line);
    }
});
$result = ['synthetic' => true, 'server_port' => 5441, 'before' => $before, 'after' => [
    'products' => DB::table('products')->count(), 'offers' => DB::table('offers')->count(), 'models' => DB::table('car_models')->count(),
], 'limitations' => ['All added offers use one supplier and all models one brand; synthetic names and fitments do not reproduce production query distribution']];
file_put_contents(base_path('output/playwright/audit-2026-10-09/load-fixtures.json'), json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result), PHP_EOL;
