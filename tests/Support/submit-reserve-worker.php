<?php

declare(strict_types=1);

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Shared\PurchaseException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    TestDatabaseGuard::assertConfiguration('testing', $input['database']);
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => $input['key'],
        'APP_CONFIG_CACHE' => 'bootstrap/cache/config.submit-race.php',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $input['database']['host'],
        'DB_PORT' => $input['database']['port'], 'DB_DATABASE' => $input['database']['database'],
        'DB_USERNAME' => $input['database']['username'], 'DB_PASSWORD' => $input['database']['password'],
        'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array', 'GEMINI_API_KEY' => '', 'GEMINI_API_KEY_SECOND' => '',
        'ORDERING_AUTO_CHECKOUT_ENABLED' => 'true',
    ];
    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = (string) $value;
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestDatabaseGuard::assertSafe($app);
    config(['ordering.auto_checkout_enabled' => true]);
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20T05:00:00+00:00'));
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$input['application_name']]);

    $buyer = BuyerContext::guest($input['token']);
    try {
        $result = $app->make(PurchaseCommands::class)->submitDraft(
            $buyer,
            $input['draft_id'],
            $input['command_key'],
            $input['quote_id'],
            $input['expected_version'],
            'confirm_retail',
        );
        echo json_encode([
            'ok' => true,
            'order_id' => $result->data['order_id'],
            'replayed' => $result->replayed,
            'path' => $result->data['path'],
        ], JSON_THROW_ON_ERROR);
    } catch (PurchaseException $exception) {
        echo json_encode(['ok' => false, 'error' => $exception->errorCode], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).':'.$exception->getMessage());
    exit(1);
}
