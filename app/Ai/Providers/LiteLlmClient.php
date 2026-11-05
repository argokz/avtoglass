<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderException;
use App\Ai\AiRequestBudget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class LiteLlmClient implements ChatProvider
{
    public function generate(array $contents, ?string $system = null, array $functions = [], ?AiRequestBudget $budget = null, ?callable $onChunk = null): array
    {
        $endpoint = rtrim((string) config('ai.litellm.endpoint'), '/');
        $model = (string) config('ai.litellm.model', 'qwen-chat');
        if ($endpoint === '') {
            throw new AiProviderException('local_configuration', true);
        }
        if ($model !== 'qwen-chat') {
            throw new AiProviderException('local_configuration');
        }
        $budget ??= new AiRequestBudget((float) config('ai.sync_budget_seconds', 20), (int) config('ai.max_attempts', 4));
        $timeout = $budget->beginAttempt((float) config('ai.litellm.timeout', 15));
        $payload = [
            'model' => $model, 'messages' => $this->messages($contents, $system),
            'max_tokens' => (int) config('ai.max_output_tokens', 512), 'temperature' => 0.2,
            'stream' => $onChunk !== null,
            // Defense in depth; the proxy key must also allow only the local alias.
            'fallbacks' => [], 'num_retries' => 0,
        ];
        if ($functions !== []) {
            $payload['tools'] = array_map(fn (array $function) => ['type' => 'function', 'function' => $this->schema($function)], $functions);
            $payload['tool_choice'] = 'auto';
        }
        $decoder = new SseResponse('local', $budget, $onChunk);
        $options = ['allow_redirects' => false, 'progress' => fn () => $decoder->check()];
        if ($onChunk) {
            $options['sink'] = CallbackSink::open(fn (string $bytes) => $decoder->receive($bytes));
        }
        $request = Http::timeout($timeout)->connectTimeout(min(3, $timeout))->withOptions($options);
        if ($key = config('ai.litellm.key')) {
            $request = $request->withToken($key);
        }
        $started = hrtime(true);
        try {
            $response = $request->post($endpoint.'/chat/completions', $payload);
        } catch (ConnectionException) {
            $decoder->check();
            $this->log('timeout', $started);
            throw new AiProviderException($decoder->hasOutput() ? 'stream_interrupted' : 'timeout', ! $decoder->hasOutput());
        }
        $decoder->check();
        $status = $response->status();
        $this->log((string) $status, $started);
        if ($response->failed()) {
            $reason = match (true) {
                $status === 429 => 'rate_limited',
                $status >= 500 => 'overloaded',
                in_array($status, [401, 403], true) => 'credentials',
                default => 'request_rejected',
            };
            throw new AiProviderException($reason, ! $decoder->hasOutput() && ($status === 429 || $status >= 500), $status);
        }
        if ($onChunk) {
            if (! $decoder->hasEvents()) {
                $decoder->consume($response->body());
            }

            return $decoder->parts();
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new AiProviderException('invalid_json', true);
        }
        $choice = $data['choices'][0] ?? [];
        if (! in_array($choice['finish_reason'] ?? null, ['stop', 'tool_calls'], true)) {
            throw new AiProviderException('finish_rejected');
        }
        $message = $choice['message'] ?? [];
        $parts = ! empty($message['content']) ? [['text' => $message['content']]] : [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $args = json_decode($call['function']['arguments'] ?? '', true);
            if (! is_array($args) || empty($call['function']['name'])) {
                throw new AiProviderException('invalid_tool_call');
            }
            $parts[] = ['functionCall' => ['id' => $call['id'] ?? '', 'name' => $call['function']['name'], 'args' => $args]];
        }
        if ($parts === []) {
            throw new AiProviderException('empty_response', true);
        }

        return $parts;
    }

    private function messages(array $contents, ?string $system): array
    {
        $messages = $system ? [['role' => 'system', 'content' => $system]] : [];
        $pending = [];
        foreach ($contents as $index => $content) {
            $text = '';
            $calls = [];
            $responses = [];
            foreach ($content['parts'] as $partIndex => $part) {
                $text .= $part['text'] ?? '';
                if (isset($part['functionCall'])) {
                    $call = $part['functionCall'];
                    $id = $call['id'] ?? 'call_'.$index.'_'.$partIndex;
                    $pending[$call['name']][] = $id;
                    $calls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $call['name'], 'arguments' => json_encode($call['args'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]];
                }
                if (isset($part['functionResponse'])) {
                    $response = $part['functionResponse'];
                    $id = isset($pending[$response['name']]) ? array_shift($pending[$response['name']]) : null;
                    if (! is_string($id) || $id === '') {
                        throw new AiProviderException('invalid_tool_history');
                    }
                    $responses[] = ['role' => 'tool', 'tool_call_id' => $id, 'content' => json_encode($response['response'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                }
            }
            if ($text !== '' || $calls !== []) {
                $message = ['role' => $content['role'] === 'model' ? 'assistant' : 'user', 'content' => $text ?: null];
                if ($calls !== []) {
                    $message['tool_calls'] = $calls;
                }
                $messages[] = $message;
            }
            array_push($messages, ...$responses);
        }

        return $messages;
    }

    private function schema(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->schema($item);
            } elseif ($key === 'type' && is_string($item)) {
                $value[$key] = strtolower($item);
            }
        }

        return $value;
    }

    private function log(string $result, int $started): void
    {
        Log::info('ai.provider', ['provider' => 'local', 'model' => 'qwen-chat', 'result' => $result, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6)]);
    }
}
