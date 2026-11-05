<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderException;
use App\Ai\AiRequestBudget;
use App\Ai\Gemini\GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final class ProviderRouter implements ChatProvider
{
    public function __construct(private readonly GeminiClient $gemini, private readonly LiteLlmClient $local) {}

    public function generate(array $contents, ?string $system = null, array $functions = [], ?AiRequestBudget $budget = null, ?callable $onChunk = null): array
    {
        $lock = null;
        for ($slot = 0; $slot < (int) config('ai.max_concurrent', 10); $slot++) {
            $candidate = Cache::lock('ai:inference:'.$slot, 40);
            if ($candidate->get()) {
                $lock = $candidate;
                break;
            }
        }
        if ($lock === null) {
            throw new AiProviderException('provider_busy', true);
        }
        $started = hrtime(true);
        try {
            $budget ??= new AiRequestBudget((float) config('ai.sync_budget_seconds', 20), (int) config('ai.max_attempts', 2));
            $budget->onAttempt(function () {
                $key = 'ai:provider-rpm:'.config('ai.mode', 'gemini_only').(config('ai.rate_limit_namespace') ? ':'.config('ai.rate_limit_namespace') : '');
                if (RateLimiter::tooManyAttempts($key, (int) config('ai.provider_requests_per_minute', 10))) {
                    throw new AiProviderException('provider_quota', true);
                }
                RateLimiter::hit($key, 60);
            });

            return $this->route($contents, $system, $functions, $budget, $onChunk);
        } finally {
            $lock->release();
            Log::info('ai.provider', ['duration_ms' => (int) ((hrtime(true) - $started) / 1e6)]);
        }
    }

    private function route(array $contents, ?string $system, array $functions, ?AiRequestBudget $budget, ?callable $onChunk): array
    {
        $budget ??= new AiRequestBudget((float) config('ai.sync_budget_seconds', 20), (int) config('ai.max_attempts', 4));
        $mode = config('ai.mode', 'gemini_only');
        if ($mode === 'gemini_only') {
            return $this->gemini->generate($contents, $system, $functions, $budget, $onChunk);
        }
        if (! in_array($mode, ['local_only', 'local_then_gemini'], true)) {
            throw new AiProviderException('provider_configuration');
        }
        $emitted = false;
        $forward = $onChunk ? function (string $delta) use (&$emitted, $onChunk): void {
            $emitted = true;
            $onChunk($delta);
        } : null;
        try {
            return $this->local->generate($contents, $system, $functions, $budget, $forward);
        } catch (AiProviderException $e) {
            if ($mode !== 'local_then_gemini' || ! $e->retryable || $emitted) {
                throw $e;
            }
            $budget->check();

            return $this->gemini->generate($contents, $system, $functions, $budget, $onChunk);
        }
    }
}
