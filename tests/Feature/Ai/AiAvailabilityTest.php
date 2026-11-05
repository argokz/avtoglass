<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_only_chat_does_not_require_a_gemini_key(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.mode' => 'local_only', 'ai.keys' => [], 'ai.litellm.endpoint' => 'http://localhost:4000/v1',
            'ai.litellm.key' => 'test-local', 'ai.litellm.model' => 'qwen-chat']);

        $this->assertTrue(settings()->aiChatEnabled());
        config(['ai.litellm.key' => '']);
        $this->assertFalse(settings()->aiChatEnabled());
    }

    public function test_unselected_or_disallowed_provider_cannot_enable_chat(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.mode' => 'local_only', 'ai.keys' => ['gemini-test'], 'ai.litellm.key' => '']);
        $this->assertFalse(settings()->aiChatEnabled());

        config(['ai.litellm.key' => 'test-local', 'ai.litellm.endpoint' => 'http://localhost:4000/v1', 'ai.litellm.model' => 'paid-model']);
        $this->assertFalse(settings()->aiChatEnabled());
        config(['ai.mode' => 'unknown']);
        $this->assertFalse(settings()->aiChatEnabled());
    }

    public function test_combined_mode_can_use_gemini_when_local_is_not_configured(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.mode' => 'local_then_gemini', 'ai.keys' => ['gemini-test'], 'ai.litellm.key' => '']);
        $this->assertTrue(settings()->aiChatEnabled());
        settings()->save(['ai_assistant' => false]);
        $this->assertFalse(settings()->aiChatEnabled());
    }

    public function test_chat_is_hidden_while_ai_tables_are_missing(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.mode' => 'gemini_only', 'ai.keys' => ['test-key']]);
        Schema::shouldReceive('hasTable')->andReturnUsing(fn (string $table): bool => $table !== 'ai_conversations');

        $this->assertFalse(settings()->aiChatEnabled());
        $this->get('/chat')->assertNotFound();
    }
}
