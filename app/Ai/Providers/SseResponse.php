<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderException;
use App\Ai\AiRequestBudget;

/** Incremental SSE decoder shared by OpenAI-compatible and Gemini endpoints. */
final class SseResponse
{
    private string $buffer = '';

    private string $text = '';

    private array $calls = [];

    private array $geminiCalls = [];

    private ?string $finish = null;

    private int $events = 0;

    private ?AiProviderException $failure = null;

    public function __construct(private readonly string $provider, private readonly AiRequestBudget $budget, private readonly mixed $onChunk) {}

    /** Retain parser errors across native write callbacks; abort on the next progress check. */
    public function receive(string $bytes): void
    {
        if ($this->failure) {
            return;
        }
        try {
            $this->consume($bytes);
        } catch (AiProviderException $e) {
            $this->failure = $e;
        }
    }

    public function check(): void
    {
        if ($this->failure) {
            throw $this->failure;
        }
        $this->budget->check();
    }

    public function consume(string $bytes): void
    {
        $this->budget->check();
        $this->buffer .= $bytes;
        if (strlen($this->buffer) > 262144) {
            throw new AiProviderException('response_too_large');
        }
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $end), "\r");
            $this->buffer = substr($this->buffer, $end + 1);
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            $event = json_decode($data, true);
            if (! is_array($event)) {
                throw new AiProviderException('invalid_json');
            }
            $this->events++;
            $this->event($event);
        }
    }

    public function hasEvents(): bool
    {
        return $this->events > 0;
    }

    public function hasOutput(): bool
    {
        return $this->text !== '';
    }

    public function parts(): array
    {
        $this->check();
        if ($this->buffer !== '') {
            $this->consume("\n");
        }
        $allowed = $this->provider === 'gemini' ? ['STOP'] : ['stop', 'tool_calls'];
        if ($this->finish === null || ! in_array($this->finish, $allowed, true)) {
            throw new AiProviderException($this->finish === null ? 'incomplete_stream' : 'finish_rejected');
        }
        $parts = $this->text !== '' ? [['text' => $this->text]] : [];
        foreach ($this->calls as $index => $call) {
            $args = json_decode($call['arguments'] ?: '{}', true);
            if (! is_array($args) || ! is_string($call['name']) || $call['name'] === '') {
                throw new AiProviderException('invalid_tool_call');
            }
            $parts[] = ['functionCall' => ['name' => $call['name'], 'args' => $args, 'id' => $call['id'] ?: 'call_'.$index]];
        }
        $parts = array_merge($parts, $this->geminiCalls);
        if ($parts === []) {
            throw new AiProviderException('empty_response', true);
        }

        return $parts;
    }

    private function event(array $event): void
    {
        if (isset($event['error'])) {
            throw new AiProviderException('stream_error');
        }
        if ($this->provider === 'gemini') {
            $candidate = $event['candidates'][0] ?? [];
            $this->finish = $candidate['finishReason'] ?? $this->finish;
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && ! is_string($part['text'])) {
                    throw new AiProviderException('invalid_stream');
                }
                if (isset($part['text']) && empty($part['thought'])) {
                    $this->emit($part['text']);
                }
                if (isset($part['functionCall'])) {
                    $this->geminiCalls[] = $part;
                }
            }

            return;
        }
        $choice = $event['choices'][0] ?? [];
        $this->finish = $choice['finish_reason'] ?? $this->finish;
        $delta = $choice['delta'] ?? [];
        if (isset($delta['content']) && is_string($delta['content'])) {
            $this->emit($delta['content']);
        }
        foreach ($delta['tool_calls'] ?? [] as $tool) {
            $index = $tool['index'] ?? 0;
            if (! is_int($index) || $index < 0 || $index > 2
                || ! is_string($tool['function']['name'] ?? '')
                || ! is_string($tool['function']['arguments'] ?? '')
                || ! is_string($tool['id'] ?? '')) {
                throw new AiProviderException('invalid_tool_call');
            }
            $this->calls[$index] ??= ['name' => '', 'arguments' => '', 'id' => ''];
            $this->calls[$index]['name'] .= $tool['function']['name'] ?? '';
            $this->calls[$index]['arguments'] .= $tool['function']['arguments'] ?? '';
            $this->calls[$index]['id'] .= $tool['id'] ?? '';
            if (strlen($this->calls[$index]['arguments']) > 65536 || strlen($this->calls[$index]['name']) > 256 || strlen($this->calls[$index]['id']) > 256) {
                throw new AiProviderException('response_too_large');
            }
        }
    }

    private function emit(string $text): void
    {
        $this->text .= $text;
        if (strlen($this->text) > 65536) {
            throw new AiProviderException('response_too_large');
        }
        if ($text !== '' && $this->onChunk) {
            ($this->onChunk)($text);
        }
    }
}
