<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\Offer;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=preview');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'preview';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'avtoglasskz_modernization_preview') {
    throw new RuntimeException('Measurements require the isolated preview database.');
}

$queryCount = 0;
$queryMs = 0.0;
DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryMs) {
    $queryCount++;
    $queryMs += $query->time;
});

$report = ['date' => gmdate(DATE_ATOM), 'database' => DB::connection()->getDatabaseName(), 'offers' => Offer::count(), 'samples_per_route' => 5, 'routes' => []];
$kernel = $app->make(Kernel::class);
foreach (['/', '/catalog', '/search?q=Camry', '/tovar/preview-camry-70', '/chat'] as $path) {
    $samples = [];
    for ($sample = 0; $sample < 6; $sample++) {
        $queryCount = 0;
        $queryMs = 0.0;
        $start = hrtime(true);
        $request = Request::create('http://127.0.0.1:8015'.$path);
        $response = $kernel->handle($request);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $kernel->terminate($request, $response);
        if ($sample > 0) {
            $samples[] = ['status' => $response->getStatusCode(), 'duration_ms' => round($elapsed, 2), 'queries' => $queryCount, 'query_ms' => round($queryMs, 2)];
        }
        unset($response, $request);
    }
    $report['routes'][$path] = $samples;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
