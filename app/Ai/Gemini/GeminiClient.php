<?php

namespace App\Ai\Gemini;

use App\Ai\AiProviderException;
use App\Ai\AiRequestBudget;
use App\Ai\Providers\CallbackSink;
use App\Ai\Providers\ChatProvider;
use App\Ai\Providers\SseResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient implements ChatProvider
{
    public function __construct(private readonly GeminiRotator $rotator, private readonly string $endpoint) {}

    public function generate(array $contents, ?string $system = null, array $functions = [], ?AiRequestBudget $budget = null, ?callable $onChunk = null): array
    {
        $budget ??= new AiRequestBudget((float) config('ai.sync_budget_seconds', 20), (int) config('ai.max_attempts', 4));
        $payload = ['contents' => $contents, 'generationConfig' => ['maxOutputTokens' => (int) config('ai.max_output_tokens', 512), 'temperature' => 0.2]];
        if ($system) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($functions !== []) {
            $payload['tools'] = [['functionDeclarations' => $functions]];
        }

        foreach ($this->rotator->candidates() as $combo) {
            $timeout = $budget->beginAttempt();
            $decoder = new SseResponse('gemini', $budget, $onChunk);
            $options = ['allow_redirects' => false, 'progress' => fn () => $decoder->check()];
            if ($onChunk) {
                $options['sink'] = CallbackSink::open(fn (string $bytes) => $decoder->receive($bytes));
            }
            $started = hrtime(true);
            try {
                $response = Http::timeout($timeout)->connectTimeout(min(3, $timeout))
                    ->withOptions($options)->withHeaders(['x-goog-api-key' => $combo['key']])
                    ->post($this->endpoint.'/'.rawurlencode($combo['model']).($onChunk ? ':streamGenerateContent?alt=sse' : ':generateContent'), $payload);
            } catch (ConnectionException) {
                // Never chain transport errors: they can contain sensitive request data.
                $decoder->check();
                $this->log($combo['model'], 'timeout', $started);
                if ($decoder->hasOutput()) {
                    throw new AiProviderException('stream_interrupted');
                }
                $this->rotator->markExhausted($combo, 1);

                continue;
            }
            $decoder->check();
            $status = $response->status();
            $this->log($combo['model'], (string) $status, $started);
            if (in_array($status, [429, 404, 502, 503, 504], true)) {
                $retryAfter = $response->header('Retry-After');
                $minutes = ctype_digit((string) $retryAfter) ? (int) ceil(min(3600, (int) $retryAfter) / 60) : null;
                $this->rotator->markExhausted($combo, $minutes ?? ($status >= 500 ? 2 : null));
                if ($decoder->hasOutput()) {
                    throw new AiProviderException('stream_interrupted');
                }

                continue;
            }
            if ($response->failed()) {
                throw new AiProviderException(in_array($status, [400, 401, 403], true) ? 'credentials_or_request' : 'upstream_error', false, $status);
            }
            if ($onChunk) {
                if (! $decoder->hasEvents()) {
                    $decoder->consume($response->body());
                }
                $parts = $decoder->parts();
            } else {
                $body = $response->json();
                if (! is_array($body)) {
                    throw new AiProviderException('invalid_json', true);
                }
                $finish = $body['candidates'][0]['finishReason'] ?? 'STOP';
                if ($finish !== 'STOP') {
                    throw new AiProviderException('finish_rejected');
                }
                $parts = $body['candidates'][0]['content']['parts'] ?? [];
                if (! is_array($parts) || $parts === []) {
                    throw new AiProviderException('empty_response', true);
                }
                $parts = array_values(array_filter($parts, fn (array $part) => empty($part['thought'])));
            }
            $this->rotator->markWorking($combo);

            return $parts;
        }

        throw new GeminiUnavailableException('unavailable', true);
    }

    private function log(string $model, string $result, int $started): void
    {
        Log::info('ai.provider', ['provider' => 'gemini', 'model' => $model, 'result' => $result, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6)]);
    }
}
