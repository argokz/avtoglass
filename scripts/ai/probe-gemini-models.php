<?php

use App\Ai\AiRequestBudget;
use App\Ai\Gemini\GeminiClient;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$client = app(GeminiClient::class);
$started = hrtime(true);
try {
    $parts = $client->generate(
        [['role' => 'user', 'parts' => [['text' => 'Ответь одним словом: ок']]]],
        null,
        [],
        new AiRequestBudget(20, 2),
    );
    $text = '';
    foreach ($parts as $part) {
        $text .= $part['text'] ?? '';
    }
    echo 'ok '.$text.PHP_EOL;
} catch (Throwable $e) {
    echo 'fail '.$e::class.' '.$e->getMessage().PHP_EOL;
}
echo 'seconds='.round((hrtime(true) - $started) / 1e9, 2).PHP_EOL;
