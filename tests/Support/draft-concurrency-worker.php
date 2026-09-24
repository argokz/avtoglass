<?php

declare(strict_types=1);

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Pricing\QuoteDraft;
use App\Domain\Shared\PurchaseException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Test-only child process. Configuration travels on stdin, never command-line arguments or logs.
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    TestDatabaseGuard::assertConfiguration('testing', $input['database']);
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => $input['key'],
        'APP_CONFIG_CACHE' => 'bootstrap/cache/config.draft-race.php',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $input['database']['host'],
        'DB_PORT' => $input['database']['port'], 'DB_DATABASE' => $input['database']['database'],
        'DB_USERNAME' => $input['database']['username'], 'DB_PASSWORD' => $input['database']['password'],
        'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array', 'GEMINI_API_KEY' => '', 'GEMINI_API_KEY_SECOND' => '',
    ];
    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = (string) $value;
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestDatabaseGuard::assertSafe($app);
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$input['application_name']]);
    if (($input['operation'] ?? 'write') === 'idempotent-create') {
        try {
            $result = $app->make(PurchaseCommands::class)->createDraft(BuyerContext::guest($input['token'], $input['locale'] ?? 'ru'), $input['command_key']);
            echo json_encode(['id' => $result->data['id'], 'locale' => $result->data['locale'], 'replayed' => $result->replayed], JSON_THROW_ON_ERROR);
        } catch (PurchaseException $exception) {
            echo json_encode(['error' => $exception->errorCode], JSON_THROW_ON_ERROR);
        }
        exit(0);
    }
    if (($input['operation'] ?? 'write') === 'quote') {
        $quote = $app->make(QuoteDraft::class)->prepare(BuyerContext::guest($input['token']), $input['draft_id'], 1);
        echo json_encode(['id' => $quote->id, 'version' => $quote->version], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if (($input['operation'] ?? 'write') === 'read') {
        $paused = false;
        DB::listen(function ($query) use (&$paused, $input) {
            if ($paused || ! str_contains($query->sql, 'from "order_drafts"')) {
                return;
            }
            $paused = true;
            // Pause between the header and items queries, while retaining the read lock.
            echo "header-read\n";
            flush();
            $deadline = microtime(true) + 20;
            do {
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$input['writer_name']])->total;
                if ($waiting === 1) {
                    return;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('The concurrent writer did not wait for the read lock.');
        });
        $view = $app->make(DraftService::class)->get(BuyerContext::guest($input['token']), $input['draft_id']);
        echo json_encode($view, JSON_THROW_ON_ERROR);
        exit(0);
    }
    try {
        $draft = $app->make(DraftService::class)->update(BuyerContext::guest($input['token']), $input['draft_id'], $input['expected_version'] ?? 1, $input['changes'] ?? [
            'contacts' => ['name' => $input['editor']],
        ]);
        echo json_encode(['result' => 'saved', 'editor' => $input['editor'], 'version' => $draft->version], JSON_THROW_ON_ERROR);
    } catch (PurchaseException $exception) {
        echo json_encode(['result' => $exception->errorCode, 'details' => $exception->details, 'editor' => $input['editor']], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    // Do not emit input, database configuration or encryption keys on failure.
    fwrite(STDERR, get_class($exception));
    exit(1);
}
