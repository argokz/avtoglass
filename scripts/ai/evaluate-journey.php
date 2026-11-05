<?php

declare(strict_types=1);

// Export is offline. --run uses preview fakes; --live explicitly uses configured providers.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\GenerateReply;
use App\Ai\Runtime\MessageText;
use App\Ai\Runtime\ToolPayload;
use App\Domain\Assistant\ShoppingService;
use App\Domain\Identity\BuyerContext;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$cases = require __DIR__.'/journey-cases.php';
if (count($cases) !== 200 || count(array_filter($cases, fn ($case) => $case['locale'] === 'ru')) !== 100) {
    throw new RuntimeException('Evaluation requires 100 RU and 100 KK conversations.');
}
$root = dirname(__DIR__, 2);
$out = $root.'/output/assistant-evaluation';
if (! is_dir($out)) {
    mkdir($out, 0770, true);
}
file_put_contents($out.'/cases.json', json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$live = in_array('--live', $argv, true);
if (! $live && ! in_array('--run', $argv, true)) {
    echo json_encode(['exported' => count($cases), 'ru' => 100, 'kk' => 100, 'network' => false]).PHP_EOL;
    exit;
}

putenv('APP_ENV=preview');
putenv('APP_CONFIG_CACHE=bootstrap/cache/config.preview.php');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'preview';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('preview') || DB::selectOne('select current_database() as name')->name !== 'avtoglasskz_modernization_preview') {
    throw new RuntimeException('Evaluation requires the isolated preview database.');
}
config(['ai.queue_name' => 'journey-evaluation-'.getmypid(), 'ai.max_attempts' => 2, 'mail.default' => 'array']);
if (! $live) {
    config(['ai.provider_requests_per_minute' => 100000, 'ai.rate_limit_namespace' => 'preview-evaluation']);
    Cache::setDefaultDriver('array');
    $app->instance(RateLimiter::class, new RateLimiter(Cache::store('array')));
    Illuminate\Support\Facades\RateLimiter::clearResolvedInstance(RateLimiter::class);
}
$limit = 200;
$only = null;
$tag = null;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/D', $arg, $match)) {
        $limit = max(2, min(200, (int) $match[1]));
    }
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
    if (preg_match('/^--tag=([a-z0-9-]{1,40})$/D', $arg, $match)) {
        $tag = $match[1];
    }
}
$requests = 0;
$started = microtime(true);
if ($live) {
    $source = Dotenv\Dotenv::parse(preg_replace('/\A\xEF\xBB\xBF/', '', file_get_contents($root.'/.env')));
    config(['ai.mode' => $source['AI_PROVIDER_MODE'] ?? 'gemini_only',
        'ai.keys' => array_values(array_filter([$source['GEMINI_API_KEY'] ?? null, $source['GEMINI_API_KEY_SECOND'] ?? null])),
        'ai.models' => isset($source['GEMINI_MODELS']) ? explode(',', $source['GEMINI_MODELS']) : config('ai.models'),
        'ai.litellm.endpoint' => $source['LITELLM_BASE_URL'] ?? '', 'ai.litellm.key' => $source['LITELLM_API_KEY'] ?? '']);
    unset($source);
    $http = new Factory;
    $http->globalRequestMiddleware(function ($request) use (&$requests, $started) {
        $host = $request->getUri()->getHost();
        if (! in_array($host, ['generativelanguage.googleapis.com', '127.0.0.1', 'localhost'], true)
            || $requests >= 400 || microtime(true) - $started > 5400) {
            throw new RuntimeException('Evaluation provider budget or host boundary reached.');
        }
        $requests++;

        return $request;
    });
    Http::swap($http);
}

$actors = [];
foreach (['retail', 'wholesale'] as $kind) {
    $customer = $kind === 'wholesale' ? Customer::factory()->wholesale(10)->create(['name' => 'Synthetic evaluation wholesale']) : Customer::factory()->create(['name' => 'Synthetic evaluation retail']);
    $actors[$kind] = User::factory()->create(['customer_id' => $customer->id]);
}
$sqlMs = 0.0;
$sqlCount = 0;
$collect = false;
DB::listen(function ($query) use (&$sqlMs, &$sqlCount, &$collect) {
    if ($collect) {
        $sqlMs += $query->time;
        $sqlCount++;
    }
});
$results = [];
$runLabel = ($live ? 'live' : 'preview').($only ? '-'.preg_replace('/[^a-z0-9-]/', '', $only) : '').(in_array('--complete', $argv, true) ? '-complete' : '');
$runLabel .= $tag ? '-'.$tag : '';
$worker = new WorkerOptions(sleep: 0, maxTries: 1, timeout: 60);
foreach (array_slice(array_values(array_filter($cases, fn ($case) => $only === null || str_ends_with($case['id'], '-'.$only))), 0, $limit) as $case) {
    $buyer = BuyerContext::account($actors[$case['buyer']]->id, $case['locale']);
    $store = app(ChatStore::class);
    $chat = $store->create($buyer);
    $turns = [];
    foreach ($case['messages'] as $message) {
        $beforeOrders = Order::query()->count();
        $beforeRequests = $requests;
        $sqlMs = 0;
        $sqlCount = 0;
        $collect = true;
        $at = microtime(true);
        $id = $store->enqueue($chat, $message);
        GenerateReply::dispatch($id);
        app('queue.worker')->runNextJob('database', config('ai.queue_name'), $worker);
        $turn = DB::table('ai_turns')->where('id', $id)->first();
        $elapsed = microtime(true) - $at;
        $collect = false;
        $newOrders = Order::query()->count() - $beforeOrders;
        $turns[] = ['status' => $turn->status, 'error' => $turn->error_code, 'runtime_seconds' => round($elapsed, 3),
            'sql_ms' => round($sqlMs, 1), 'sql_queries' => $sqlCount, 'provider_requests' => $requests - $beforeRequests,
            'reply' => MessageText::decode((string) DB::table('ai_messages')->where('id', $turn->model_message_id)->value('text')),
            'tools' => DB::table('ai_tool_results')->where('turn_id', $id)->get()->map(fn ($row) => ['name' => $row->name, 'result' => ToolPayload::decode($row->result)])->all(),
            'new_orders' => $newOrders];
        if ($newOrders !== 0) {
            throw new RuntimeException('Unexpected order creation: evaluation stopped.');
        }
        if ($live && $requests > $beforeRequests) {
            // Provider admission wait is outside measured runtime; actual quota failures are retained.
            usleep(6200000);
        }
    }
    $results[] = [...$case, 'conversation_id' => $chat->id, 'turns' => $turns, 'state' => app(ShoppingService::class)->context($chat->fresh(), $buyer)];
    file_put_contents($out.'/'.$runLabel.'-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo json_encode(['case' => $case['id'], 'completed' => count($results), 'provider_requests' => $requests]).PHP_EOL;
    if ($live && ! in_array('--complete', $argv, true) && count($results) >= 4 && count(array_filter(array_slice($results, -4), fn ($result) => end($result['turns'])['status'] === 'failed')) === 4) {
        break;
    }
}
$seconds = array_merge([], ...array_map(fn ($result) => array_column($result['turns'], 'runtime_seconds'), $results));
sort($seconds);
echo json_encode(['evaluated' => count($results), 'live' => $live, 'provider_requests' => $requests,
    'runtime_p95_seconds' => $seconds[(int) ceil(count($seconds) * 0.95) - 1] ?? null,
    'human_quality' => 'not_scored', 'browser_delivery' => 'not_measured']).PHP_EOL;
