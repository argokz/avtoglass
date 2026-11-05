<?php

namespace Tests\Unit\Ai;

use App\Ai\AiProviderException;
use App\Ai\AiRequestBudget;
use App\Ai\Providers\CallbackSink;
use App\Ai\Providers\LiteLlmClient;
use App\Ai\Providers\SseResponse;
use PHPUnit\Framework\TestCase;

class RequestBudgetAndStreamTest extends TestCase
{
    public function test_attempt_budget_is_shared_across_steps(): void
    {
        $budget = new AiRequestBudget(20, 2);
        $this->assertLessThanOrEqual(3, $budget->beginAttempt(3));
        $budget->beginAttempt(3);
        $this->expectExceptionMessage('attempt_limit');
        $budget->beginAttempt(3);
    }

    public function test_deadline_clamps_request_timeout_and_expires(): void
    {
        $budget = new AiRequestBudget(0.1);
        $this->assertLessThanOrEqual(0.1, $budget->beginAttempt(15));
        usleep(110000);
        $this->expectExceptionMessage('deadline');
        $budget->check();
    }

    public function test_cancellation_prevents_attempt(): void
    {
        $budget = new AiRequestBudget(20, 4, fn () => true);
        $this->expectExceptionMessage('cancelled');
        $budget->beginAttempt(3);
    }

    public function test_php_execution_limit_leaves_time_for_error_handling(): void
    {
        $previous = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        try {
            $this->assertLessThanOrEqual(25, (new AiRequestBudget(30))->beginAttempt(30));
        } finally {
            ini_set('max_execution_time', (string) $previous);
        }
    }

    public function test_litellm_tool_reply_follows_assistant_tool_call(): void
    {
        $messages = (new \ReflectionMethod(LiteLlmClient::class, 'messages'))->invoke(new LiteLlmClient, [
            ['role' => 'model', 'parts' => [['functionCall' => ['id' => 'c1', 'name' => 'search_products', 'args' => ['year' => 2020]]]]],
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'search_products', 'response' => ['offers' => []]]]]],
        ], null);

        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertSame('c1', $messages[0]['tool_calls'][0]['id']);
        $this->assertSame('tool', $messages[1]['role']);
        $this->assertSame('c1', $messages[1]['tool_call_id']);
    }

    public function test_litellm_rejects_unmatched_tool_reply(): void
    {
        $this->expectExceptionMessage('invalid_tool_history');
        (new \ReflectionMethod(LiteLlmClient::class, 'messages'))->invoke(new LiteLlmClient, [
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'search_products', 'response' => []]]]],
        ], null);
    }

    public function test_real_native_sink_emits_deltas_before_end_of_response(): void
    {
        $chunks = [];
        $decoder = new SseResponse('local', new AiRequestBudget, function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $sink = CallbackSink::open(fn ($bytes) => $decoder->receive($bytes));
        $first = $this->event(['choices' => [['delta' => ['content' => 'Қай ']]]]);
        fwrite($sink, substr($first, 0, 12));
        $this->assertSame([], $chunks);
        fwrite($sink, substr($first, 12));
        $this->assertSame(['Қай '], $chunks);
        fwrite($sink, $this->event(['choices' => [['delta' => ['content' => 'жыл?'], 'finish_reason' => 'stop']]]));
        $this->assertSame([['text' => 'Қай жыл?']], $decoder->parts());
        fclose($sink);
    }

    public function test_fragmented_tool_arguments_are_not_sent_as_text(): void
    {
        $chunks = [];
        $decoder = new SseResponse('local', new AiRequestBudget, function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $tool = ['index' => 0, 'id' => 'c1', 'function' => ['name' => 'search_products', 'arguments' => '{"year":']];
        $decoder->consume($this->event(['choices' => [['delta' => ['tool_calls' => [$tool]]]]]));
        $decoder->consume($this->event(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '2020}']]]], 'finish_reason' => 'tool_calls']]]));
        $this->assertSame([], $chunks);
        $this->assertSame(['name' => 'search_products', 'args' => ['year' => 2020], 'id' => 'c1'], $decoder->parts()[0]['functionCall']);
    }

    public function test_missing_finish_marker_rejects_truncated_stream(): void
    {
        $decoder = new SseResponse('local', new AiRequestBudget, null);
        $decoder->consume($this->event(['choices' => [['delta' => ['content' => 'partial']]]]));
        $this->expectExceptionMessage('incomplete_stream');
        $decoder->parts();
    }

    public function test_gemini_finish_reason_and_thoughts_are_checked(): void
    {
        $chunks = [];
        $decoder = new SseResponse('gemini', new AiRequestBudget, function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $decoder->consume($this->event(['candidates' => [['content' => ['parts' => [['text' => 'hidden', 'thought' => true], ['text' => 'answer']]], 'finishReason' => 'MAX_TOKENS']]]));
        $this->assertSame(['answer'], $chunks);
        $this->expectExceptionMessage('finish_rejected');
        $decoder->parts();
    }

    public function test_bad_json_error_survives_native_callback(): void
    {
        $decoder = new SseResponse('local', new AiRequestBudget, null);
        $sink = CallbackSink::open(fn ($bytes) => $decoder->receive($bytes));
        fwrite($sink, "data: invalid\n\n");
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('invalid_json');
        $decoder->parts();
    }

    public function test_malformed_stream_tool_fragment_is_a_controlled_provider_error(): void
    {
        $decoder = new SseResponse('local', new AiRequestBudget, null);
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('invalid_tool_call');
        $decoder->consume($this->event(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['name' => ['unexpected']]]]]]]]));
    }

    private function event(array $data): string
    {
        return 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
    }
}
