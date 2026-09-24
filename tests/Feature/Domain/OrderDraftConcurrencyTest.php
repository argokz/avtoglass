<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\Models\OrderDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Independent PostgreSQL sessions must see committed fixtures, so do not use RefreshDatabase's transaction. */
class OrderDraftConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_read_returns_matching_version_and_items_during_a_concurrent_replacement(): void
    {
        $token = bin2hex(random_bytes(32));
        $buyer = BuyerContext::guest($token);
        $service = app(DraftService::class);
        $draft = $service->create($buyer);
        $oldItem = ['line_id' => (string) Str::uuid(), 'reference' => 'OLD', 'quantity' => 1];
        $service->update($buyer, $draft->id, 1, ['items' => [$oldItem]]);
        $prefix = 'draft-read-'.substr((string) Str::uuid(), 0, 12);
        $common = ['database' => DB::connection()->getConfig(), 'key' => config('app.key'), 'draft_id' => $draft->id, 'token' => $token];
        $reader = $this->worker($common + ['operation' => 'read', 'application_name' => $prefix.'-reader', 'writer_name' => $prefix.'-writer']);
        $writer = null;
        try {
            $reader->start();
            $deadline = microtime(true) + 30;
            while (! str_contains($reader->getOutput(), 'header-read') && $reader->isRunning() && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $this->assertStringContainsString('header-read', $reader->getOutput(), $reader->getErrorOutput());
            $writer = $this->worker($common + [
                'application_name' => $prefix.'-writer', 'editor' => 'B', 'expected_version' => 2,
                'changes' => ['items' => [['line_id' => (string) Str::uuid(), 'reference' => 'NEW', 'quantity' => 9]]],
            ]);
            $writer->start();
            $this->assertSame(0, $reader->wait(), $reader->getErrorOutput());
            $this->assertSame(0, $writer->wait(), $writer->getErrorOutput());
            $read = json_decode(substr($reader->getOutput(), strlen("header-read\n")), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(2, $read['version']);
            $this->assertSame('OLD', $read['items'][0]['reference']);
            $this->assertSame(1, $read['items'][0]['quantity']);
            $current = $service->get($buyer, $draft->id)->jsonSerialize();
            $this->assertSame(3, $current['version']);
            $this->assertSame('NEW', $current['items'][0]['reference']);
            $this->assertSame(9, $current['items'][0]['quantity']);
        } finally {
            foreach ([$reader, $writer] as $process) {
                if ($process?->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Support/draft-concurrency-worker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(60);

        return $process;
    }

    public function test_two_simultaneous_writers_save_once_and_return_a_conflict_to_the_other(): void
    {
        $token = bin2hex(random_bytes(32));
        $buyer = BuyerContext::guest($token);
        $draft = app(DraftService::class)->create($buyer);
        $prefix = 'draft-race-'.substr((string) Str::uuid(), 0, 12);
        $processes = [];
        DB::beginTransaction();
        try {
            OrderDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            foreach (['A', 'B'] as $editor) {
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Support/draft-concurrency-worker.php')], base_path());
                $process->setInput(json_encode([
                    'database' => DB::connection()->getConfig(), 'key' => config('app.key'),
                    'draft_id' => $draft->id, 'token' => $token, 'editor' => $editor, 'application_name' => $prefix.'-'.$editor,
                ], JSON_THROW_ON_ERROR));
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            // Hold the row until BOTH real backend connections are waiting for a lock.
            $deadline = microtime(true) + 50;
            do {
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = 'Lock'", [$prefix.'-A', $prefix.'-B'])->total;
                if ($waiting === 2) {
                    break;
                }
                if (! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    $this->fail('A concurrency worker exited before reaching the lock: '.$processes[0]->getErrorOutput().$processes[1]->getErrorOutput());
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both writers must overlap while waiting for the same draft.');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertEqualsCanonicalizing(['saved', 'DRAFT_VERSION_CONFLICT'], array_column($results, 'result'));
            $winner = collect($results)->firstWhere('result', 'saved');
            $loser = collect($results)->firstWhere('result', 'DRAFT_VERSION_CONFLICT');
            $this->assertSame(2, $winner['version']);
            $this->assertSame(['current_version' => 2], $loser['details']);
            $saved = app(DraftService::class)->get($buyer, $draft->id)->jsonSerialize();
            $this->assertSame(2, $saved['version']);
            $this->assertSame($winner['editor'], $saved['contacts']['name']);
            $this->assertDatabaseCount('order_drafts', 1);
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
