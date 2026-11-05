<?php

/** Synthetic local transport check; never calls shop tools or writes orders. */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Ai\AiProviderException;
use App\Ai\AiRequestBudget;
use App\Ai\Providers\LiteLlmClient;
use Illuminate\Contracts\Console\Kernel;

$options = getopt('', ['env-file:', 'scenario::']);
if (empty($options['env-file'])) {
    fwrite(STDERR, "Usage: php scripts/ai/probe-provider.php --env-file=PATH [--scenario=text|tools]\n");
    exit(1);
}
$local = Dotenv\Dotenv::parse(file_get_contents($options['env-file']));
$endpoint = rtrim($local['LITELLM_BASE_URL'] ?? '', '/');
$key = $local['LITELLM_API_KEY_1'] ?? $local['LITELLM_API_KEY'] ?? '';
if ($endpoint === '' || $key === '') {
    throw new RuntimeException('Local provider configuration is missing.');
}
if (! str_ends_with($endpoint, '/v1')) {
    $endpoint .= '/v1';
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['ai.litellm.endpoint' => $endpoint, 'ai.litellm.key' => $key, 'ai.litellm.model' => 'qwen-chat', 'ai.max_output_tokens' => 180]);
unset($local, $key);
$scenario = $options['scenario'] ?? 'text';
$functions = $scenario === 'tools' ? [[
    'name' => 'search_products', 'description' => 'Search synthetic glass catalogue',
    'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'year' => ['type' => 'integer']], 'required' => ['query', 'year']],
]] : [];
$prompt = $scenario === 'tools'
    ? 'Найди лобовое стекло Toyota Camry 2018. Только вызови search_products, не выдумывай цену.'
    : 'Нужна лобовуха на Camry 2018. Каталога сейчас нет. Ответь одним вопросом о камере ADAS за лобовым стеклом, не о камере заднего вида.';
$started = hrtime(true);
$first = null;
$chunks = 0;
try {
    $parts = app(LiteLlmClient::class)->generate([['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'Ты помощник магазина автостёкол. Не выдумывай цену и совместимость.', $functions, new AiRequestBudget(20, 1),
        function (string $chunk) use (&$first, &$chunks, $started): void {
            $first ??= round((hrtime(true) - $started) / 1e9, 3);
            $chunks++;
        });
    $result = ['scenario' => $scenario, 'status' => 'passed', 'first_chunk_seconds' => $first, 'chunks' => $chunks, 'parts' => $parts];
} catch (AiProviderException $exception) {
    $result = ['scenario' => $scenario, 'status' => 'failed', 'reason' => $exception->getMessage()];
}
$result['seconds'] = round((hrtime(true) - $started) / 1e9, 3);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['status'] === 'passed' ? 0 : 1);
