<?php

namespace App\Ai;

final class AiRequestBudget
{
    private float $seconds;

    private float $started;

    private int $attempts = 0;

    private float $lastCancellationCheck = -1;

    private mixed $onAttempt = null;

    public function onAttempt(callable $callback): void
    {
        $this->onAttempt = $callback;
    }

    public function __construct(float $seconds = 20, private readonly int $maxAttempts = 2, private readonly mixed $cancelled = null)
    {
        // Leave room for Laravel to handle and persist a timeout before PHP's own fatal deadline.
        $phpLimit = (int) ini_get('max_execution_time');
        $this->seconds = $phpLimit > 0 ? min($seconds, max(0.05, $phpLimit - 5)) : $seconds;
        $this->started = hrtime(true) / 1e9;
    }

    public function check(bool $forceCancellationCheck = false): void
    {
        // cURL progress callbacks can fire many times per token. Do not query storage for every tick.
        $elapsed = $this->elapsed();
        if ($this->cancelled && ($forceCancellationCheck || $elapsed - $this->lastCancellationCheck >= 0.2)) {
            $this->lastCancellationCheck = $elapsed;
            if (($this->cancelled)()) {
                throw new AiProviderException('cancelled');
            }
        }
        if ($this->remaining() <= 0.05) {
            throw new AiProviderException('deadline');
        }
    }

    public function beginAttempt(?float $requestTimeout = null): float
    {
        $this->check();
        if ($this->attempts >= $this->maxAttempts) {
            throw new AiProviderException('attempt_limit');
        }
        if ($this->onAttempt) {
            ($this->onAttempt)();
        }
        $this->attempts++;

        return max(0.05, min($this->remaining(), $requestTimeout ?? (float) config('ai.request_timeout', 8)));
    }

    public function remaining(): float
    {
        return max(0, $this->seconds - $this->elapsed());
    }

    public function elapsed(): float
    {
        return hrtime(true) / 1e9 - $this->started;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
