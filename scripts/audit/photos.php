<?php

declare(strict_types=1);
use App\Models\CarModel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('preview') || DB::selectOne('SELECT current_database() AS name')->name !== 'avtoglasskz_modernization_preview') {
    throw new RuntimeException('Photo fixtures require preview');
}
// Reference existing public media only; do not copy or modify source photographs.
$path = 'models/toyota-4runner.jpg';
if (! is_file(storage_path('app/public/'.$path))) {
    throw new RuntimeException('Representative public photo missing');
}
$model = CarModel::firstOrFail();
$model->image_path = $path;
$model->save();
echo json_encode(['fixture' => $model->slug, 'photo' => $path, 'dimensions' => array_slice(getimagesize(storage_path('app/public/'.$path)), 0, 2)]), PHP_EOL;
