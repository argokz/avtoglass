<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestDatabaseGuard;

// Read-only environment check. Never print connection strings or credentials.
require dirname(__DIR__).'/vendor/autoload.php';

$testing = in_array('--testing', $argv, true);

if ($testing) {
    $xml = simplexml_load_file(dirname(__DIR__).'/phpunit.xml');
    foreach ($xml->php->env as $entry) {
        $name = (string) $entry['name'];
        $value = (string) $entry['value'];
        if ((string) $entry['force'] === 'true' || getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$required = ['pdo_pgsql', 'mbstring', 'intl', 'curl', 'zip', 'dom', 'xmlreader', 'xmlwriter', 'fileinfo', 'openssl'];
$missing = array_values(array_filter($required, fn ($extension) => ! extension_loaded($extension)));
$report = [
    'php' => PHP_VERSION,
    'php_binary' => PHP_BINARY,
    'laravel' => $app->version(),
    'environment' => $app->environment(),
    'missing_extensions' => $missing,
    'database' => ['status' => 'unavailable'],
    'cache' => config('cache.default'),
    'queue' => config('queue.default'),
    'session' => config('session.driver'),
];

try {
    if ($testing) {
        TestDatabaseGuard::assertSafe($app);
    }
    $db = $app->make('db')->connection();
    $identity = $db->selectOne('SELECT current_database() AS name, version() AS version');
    $report['database'] = ['status' => 'ok', 'name' => $identity->name, 'version' => $identity->version];
} catch (Throwable $exception) {
    $report['database']['error_type'] = get_class($exception);
}

$report['ok'] = PHP_VERSION_ID >= 80401 && $missing === [] && $report['database']['status'] === 'ok';
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($report['ok'] ? 0 : 1);
