<?php

declare(strict_types=1);

namespace App\Ai\Runtime;

/** SQL timings only: query text and bindings never enter assistant metrics. */
final class RuntimeMetrics
{
    private bool $active = false;

    private float $sqlMs = 0;

    private int $queries = 0;

    public function begin(): void
    {
        $this->active = true;
        $this->sqlMs = 0;
        $this->queries = 0;
    }

    public function query(float $milliseconds): void
    {
        if ($this->active) {
            $this->sqlMs += $milliseconds;
            $this->queries++;
        }
    }

    public function finish(): array
    {
        $this->active = false;

        return ['sql_ms' => (int) round($this->sqlMs), 'sql_queries' => $this->queries];
    }
}
