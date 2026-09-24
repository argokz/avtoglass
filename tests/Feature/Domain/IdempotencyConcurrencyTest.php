<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Shared\Commands\IdempotencyRecord;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IdempotencyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('requests')]
    public function test_competing_requests_recover_after_original_transaction_rolls_back(bool $differentBody): void
    {
        $token = bin2hex(random_bytes(32));
        $key = 'same-intention';
        $prefix = 'idem-race-'.substr((string) Str::uuid(), 0, 12);
        $processes = [];
        DB::beginTransaction();
        try {
            // Hold a real, uncommitted command result. Its later rollback simulates a failed leader.
            $uncommitted = app(PurchaseCommands::class)->createDraft(BuyerContext::guest($token), $key);
            foreach (['A', 'B'] as $editor) {
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Support/draft-concurrency-worker.php')], base_path());
                $process->setInput(json_encode([
                    'database' => DB::connection()->getConfig(), 'key' => config('app.key'),
                    'token' => $token, 'operation' => 'idempotent-create', 'application_name' => $prefix.'-'.$editor,
                    'locale' => $differentBody && $editor === 'B' ? 'kk' : 'ru',
                    // The worker uses this same value as the idempotency key after bootstrapping.
                    'command_key' => $key,
                ], JSON_THROW_ON_ERROR));
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 50;
            do {
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = 'Lock'", [$prefix.'-A', $prefix.'-B'])->total;
                if ($waiting === 2) {
                    break;
                }
                if (! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    $this->fail('A worker exited before the unique-key wait: '.$processes[0]->getErrorOutput().$processes[1]->getErrorOutput());
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both independent requests must wait for the original uncommitted key.');
            DB::rollBack();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $success = collect($results)->filter(fn ($result) => isset($result['id']))->values();
            $this->assertSame($differentBody ? 1 : 2, $success->count());
            $this->assertNotSame($uncommitted->data['id'], $success[0]['id']);
            if ($differentBody) {
                $this->assertSame('IDEMPOTENCY_CONFLICT', collect($results)->firstWhere('error', 'IDEMPOTENCY_CONFLICT')['error']);
                $this->assertFalse($success[0]['replayed']);
            } else {
                $this->assertSame($success[0]['id'], $success[1]['id']);
                $this->assertEqualsCanonicalizing([false, true], $success->pluck('replayed')->all());
            }
            $this->assertDatabaseCount('order_drafts', 1);
            $this->assertDatabaseCount('idempotency_records', 1);
            $this->assertSame($success[0]['id'], IdempotencyRecord::sole()->result_id);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    public static function requests(): array
    {
        return ['identical bodies' => [false], 'different bodies' => [true]];
    }
}
