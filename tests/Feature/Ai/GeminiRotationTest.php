<?php

namespace Tests\Feature\Ai;

use App\Ai\Gemini\GeminiClient;
use App\Ai\Gemini\GeminiRotator;
use App\Ai\Gemini\GeminiUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Круг «ключ + модель»: 429 → связка отдыхает, работа продолжается
 * со следующей. Последняя рабочая запоминается и идёт первой.
 */
class GeminiRotationTest extends TestCase
{
    use RefreshDatabase;

    private function клиент(array $keys = ['k1', 'k2'], array $models = ['m1', 'm2']): GeminiClient
    {
        return new GeminiClient(new GeminiRotator($keys, $models, 60), 'https://generativelanguage.googleapis.com/v1beta/models');
    }

    private function содержание(): array
    {
        return [['role' => 'user', 'parts' => [['text' => 'привет']]]];
    }

    public function test_лимит_модели_переключает_на_следующую(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/m1:generateContent')
            ? Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)
            : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok-m2']]]]]]));

        $parts = $this->клиент()->generate($this->содержание());

        $this->assertSame('ok-m2', $parts[0]['text']);
        Http::assertSentCount(2);
    }

    public function test_уставшая_связка_пропускается_а_рабочая_идёт_первой(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/m1:generateContent')
            ? Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)
            : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]));

        $client = $this->клиент();
        $client->generate($this->содержание());
        $client->generate($this->содержание());

        // Второй вызов не дёргает уставшую m1: сразу m2.
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/m2:generateContent'));
        $m1Calls = collect(Http::recorded())->filter(
            fn ($pair) => str_contains($pair[0]->url(), '/m1:generateContent')
        )->count();
        $this->assertSame(1, $m1Calls);
    }

    public function test_лимит_первого_ключа_переключает_на_второй(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), 'key=k1')
            ? Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)
            : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok-k2']]]]]]));

        $parts = $this->клиент()->generate($this->содержание());

        $this->assertSame('ok-k2', $parts[0]['text']);
        Http::assertSentCount(3); // k1×m1, k1×m2, k2×m1
    }

    public function test_когда_все_устали_честно_говорим_об_этом(): void
    {
        Http::fake(fn () => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429));

        $this->expectException(GeminiUnavailableException::class);

        $this->клиент()->generate($this->содержание());
    }

    public function test_после_паузы_связка_снова_в_круге(): void
    {
        $rotator = new GeminiRotator(['k1'], ['m1', 'm2'], 60);
        $combo = ['key' => 'k1', 'model' => 'm1'];

        $rotator->markExhausted($combo);
        $this->assertTrue($rotator->isExhausted($combo));
        $this->assertSame(['m2'], array_column($rotator->candidates(), 'model'));

        // Пауза истекла — запись в кеше ушла, связка снова доступна.
        Cache::flush();
        $this->assertFalse($rotator->isExhausted($combo));
        $this->assertCount(2, $rotator->candidates());
    }

    public function test_список_моделей_переопределяется_из_crm(): void
    {
        settings()->save(['ai_models' => "gemini-crm-a\ngemini-crm-b"]);

        Http::fake(fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]));

        config(['ai.keys' => ['k1']]);
        app(GeminiClient::class)->generate($this->содержание());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/gemini-crm-a:generateContent'));
    }

    public function test_ошибка_не_лимита_не_крутит_круг(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'API key not valid']], 400));

        try {
            $this->клиент()->generate($this->содержание());
            $this->fail('Ждали RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_перегрузка_модели_переключает_на_следующую(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/m1:generateContent')
            ? Http::response(['error' => ['status' => 'UNAVAILABLE', 'message' => 'high demand']], 503)
            : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok-m2']]]]]]));

        $parts = $this->клиент()->generate($this->содержание());

        $this->assertSame('ok-m2', $parts[0]['text']);
        Http::assertSentCount(2);
    }

    public function test_снятая_модель_пропускается_как_лимит(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/m1:generateContent')
            ? Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'model is no longer available to new users']], 404)
            : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok-m2']]]]]]));

        $parts = $this->клиент()->generate($this->содержание());

        $this->assertSame('ok-m2', $parts[0]['text']);
        Http::assertSentCount(2);
    }
}
