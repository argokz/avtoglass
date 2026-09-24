<?php

// Creates only a dedicated local preview database; never resets an existing one.
require dirname(__DIR__).'/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
$sourceText = preg_replace('/\A\xEF\xBB\xBF/', '', file_get_contents($root.'/.env'));
$source = Dotenv::parse($sourceText);
$target = 'avtoglasskz_modernization_preview';

if (! in_array($source['DB_HOST'] ?? '', ['127.0.0.1', 'localhost', '::1'], true) || ! empty($source['DB_URL'])) {
    fwrite(STDERR, "Preview setup requires a local PostgreSQL connection without DB_URL.\n");
    exit(1);
}

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres;connect_timeout=5', $source['DB_HOST'], (int) ($source['DB_PORT'] ?? 5432));
$db = new PDO($dsn, $source['DB_USERNAME'] ?? 'postgres', $source['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $db->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$exists->execute([$target]);
if (! $exists->fetchColumn()) {
    $db->exec('CREATE DATABASE "'.$target.'"');
}

$path = $root.'/.env.preview';
if (! file_exists($path)) {
    $values = [
        'APP_NAME' => 'AvtoGlass Preview',
        'APP_ENV' => 'preview',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_DEBUG' => 'true',
        'APP_URL' => 'http://127.0.0.1:8015',
        'APP_CONFIG_CACHE' => 'bootstrap/cache/config.preview.php',
        'APP_LOCALE' => 'ru',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $source['DB_HOST'],
        'DB_PORT' => $source['DB_PORT'] ?? '5432',
        'DB_DATABASE' => $target,
        'DB_USERNAME' => $source['DB_USERNAME'] ?? 'postgres',
        'DB_PASSWORD' => $source['DB_PASSWORD'] ?? '',
        'SESSION_DRIVER' => 'database',
        'SESSION_COOKIE' => 'avtoglass_modernization_preview',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'array',
        'PAYMENT_DRIVER' => 'test',
        'GEMINI_API_KEY' => 'preview-fake-key',
        'TELEGRAM_ALERTS_ENABLED' => 'false',
    ];
    $contents = '';
    foreach ($values as $key => $value) {
        // Escape dollars as well, so a password containing ${...} stays literal.
        $quoted = str_replace('$', '\\$', json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $contents .= $key.'='.$quoted.PHP_EOL;
    }
    if (Dotenv::parse($contents) !== array_map('strval', $values)) {
        throw new RuntimeException('Preview environment could not be encoded safely.');
    }
    file_put_contents($path, $contents);
}

echo "Preview database is available; .env.preview is ready. No database was reset.\n";
