<?php

namespace Tests\Feature\Ai;

use App\Ai\AiProviderException;
use App\Ai\AiRequestBudget;
use App\Ai\Gemini\GeminiClient;
use App\Ai\Gemini\GeminiRotator;
use App\Ai\Providers\LiteLlmClient;
use App\Ai\Providers\ProviderRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** No database access; clients use HTTP fakes and the array cache. */
class ProviderTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.litellm.endpoint' => 'http://local.test/v1', 'ai.litellm.key' => 'secret-local', 'ai.litellm.model' => 'qwen-chat']);
    }

    public function test_local_stream_emits_text_and_disables_proxy_retries_and_fallbacks(): void
    {
        Http::fake(['local.test/*' => Http::response($this->event(['choices' => [['delta' => ['content' => 'Уточните год.'], 'finish_reason' => 'stop']]]), 200, ['Content-Type' => 'text/event-stream'])]);
        $chunks = [];
        $parts = (new LiteLlmClient)->generate($this->contents(), null, [], new AiRequestBudget, function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $this->assertSame(['Уточните год.'], $chunks);
        $this->assertSame('Уточните год.', $parts[0]['text']);
        Http::assertSent(fn (Request $request) => $request['fallbacks'] === [] && $request['num_retries'] === 0 && $request['stream'] === true && $request['model'] === 'qwen-chat');
    }

    public function test_local_rate_limit_uses_gemini_within_shared_attempt_budget(): void
    {
        config(['ai.mode' => 'local_then_gemini']);
        Http::fake(['local.test/*' => Http::response([], 429), 'gemini.test/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]])]);
        $budget = new AiRequestBudget(20, 2);
        $parts = $this->router()->generate($this->contents(), null, [], $budget);
        $this->assertSame('ok', $parts[0]['text']);
        $this->assertSame(2, $budget->attempts());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'gemini.test') && ! str_contains($request->url(), 'secret') && $request->hasHeader('x-goog-api-key', 'secret-gemini'));
    }

    public function test_local_only_never_calls_gemini(): void
    {
        config(['ai.mode' => 'local_only']);
        Http::fake(['local.test/*' => Http::response([], 503)]);
        try {
            $this->router()->generate($this->contents());
            $this->fail('Expected overload');
        } catch (AiProviderException $e) {
            $this->assertSame('overloaded', $e->reason);
        }
        Http::assertSentCount(1);
    }

    public function test_partial_stream_failure_does_not_switch_provider_or_duplicate_text(): void
    {
        config(['ai.mode' => 'local_then_gemini']);
        Http::fake(['local.test/*' => Http::response($this->event(['choices' => [['delta' => ['content' => 'partial']]]]).$this->event(['error' => ['message' => 'secret detail']]), 200)]);
        $chunks = [];
        try {
            $this->router()->generate($this->contents(), null, [], new AiRequestBudget, function ($chunk) use (&$chunks) {
                $chunks[] = $chunk;
            });
            $this->fail('Expected stream failure');
        } catch (AiProviderException $e) {
            $this->assertSame('stream_error', $e->reason);
            $this->assertStringNotContainsString('secret detail', $e->getMessage());
        }
        $this->assertSame(['partial'], $chunks);
        Http::assertSentCount(1);
    }

    public function test_tool_result_round_trip_preserves_id_and_json_arguments(): void
    {
        Http::fake(fn () => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]]]));
        $contents = array_merge($this->contents(), [
            ['role' => 'model', 'parts' => [['functionCall' => ['id' => 'tool-123', 'name' => 'search_products', 'args' => ['year' => 2020]]]]],
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'search_products', 'response' => ['offers' => []]]]]],
        ]);
        (new LiteLlmClient)->generate($contents);
        Http::assertSent(fn (Request $request) => $request['messages'][1]['tool_calls'][0]['id'] === 'tool-123'
            && $request['messages'][2]['tool_call_id'] === 'tool-123'
            && json_decode($request['messages'][1]['tool_calls'][0]['function']['arguments'], true) === ['year' => 2020]);
    }

    public function test_attempt_exhaustion_prevents_fallback_request(): void
    {
        config(['ai.mode' => 'local_then_gemini']);
        Http::fake(['local.test/*' => Http::response([], 429)]);
        try {
            $this->router()->generate($this->contents(), null, [], new AiRequestBudget(20, 1));
            $this->fail('Expected attempt limit');
        } catch (AiProviderException $e) {
            $this->assertSame('attempt_limit', $e->reason);
        }
        Http::assertSentCount(1);
    }

    public function test_unapproved_model_is_rejected_before_any_network_request(): void
    {
        config(['ai.litellm.model' => 'paid-model']);
        try {
            (new LiteLlmClient)->generate($this->contents());
            $this->fail('Expected configuration error');
        } catch (AiProviderException $e) {
            $this->assertSame('local_configuration', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_gemini_stream_hides_thoughts_and_requires_complete_response(): void
    {
        Http::fake(['gemini.test/*' => Http::response($this->event(['candidates' => [['content' => ['parts' => [['text' => 'reason', 'thought' => true], ['text' => 'answer']]], 'finishReason' => 'STOP']]]), 200)]);
        $chunks = [];
        $parts = $this->gemini()->generate($this->contents(), null, [], new AiRequestBudget, function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $this->assertSame(['answer'], $chunks);
        $this->assertSame('answer', $parts[0]['text']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), ':streamGenerateContent?alt=sse') && ! str_contains($request->url(), 'secret-gemini'));
    }

    private function contents(): array
    {
        return [['role' => 'user', 'parts' => [['text' => 'Подберите стекло']]]];
    }

    private function gemini(): GeminiClient
    {
        return new GeminiClient(new GeminiRotator(['secret-gemini'], ['gemini-test-flash'], 60), 'https://gemini.test/models');
    }

    private function router(): ProviderRouter
    {
        return new ProviderRouter($this->gemini(), new LiteLlmClient);
    }

    private function event(array $data): string
    {
        return 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
    }
}
