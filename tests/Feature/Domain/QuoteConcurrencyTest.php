<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Pricing\Models\CheckoutQuote;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QuoteConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_preparations_assign_distinct_versions_and_leave_one_prepared_quote(): void
    {
        $token = bin2hex(random_bytes(32));
        $draft = app(DraftService::class)->create(BuyerContext::guest($token));
        $prefix = 'quote-race-'.substr((string) Str::uuid(), 0, 12);
        $processes = [];
        DB::beginTransaction();
        try {
            OrderDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            foreach (['A', 'B'] as $editor) {
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Support/draft-concurrency-worker.php')], base_path());
                $process->setInput(json_encode([
                    'database' => DB::connection()->getConfig(), 'key' => config('app.key'), 'operation' => 'quote',
                    'draft_id' => $draft->id, 'token' => $token, 'application_name' => $prefix.'-'.$editor,
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
                    $this->fail('A quote worker exited before reaching the lock: '.$processes[0]->getErrorOutput().$processes[1]->getErrorOutput());
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting);
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertEqualsCanonicalizing([1, 2], array_column($results, 'version'));
            $this->assertNotSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, CheckoutQuote::where('status', 'prepared')->count());
            $this->assertSame(2, CheckoutQuote::where('status', 'prepared')->sole()->version);
            $this->assertSame('superseded', CheckoutQuote::where('version', 1)->sole()->status);
            $this->assertSame(1, OrderDraft::findOrFail($draft->id)->version);
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
}
