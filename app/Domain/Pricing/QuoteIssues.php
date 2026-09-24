<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class QuoteIssues
{
    private array $issues = [];

    public function add(string $code, string $severity = 'review', ?string $lineId = null, ?string $field = null): void
    {
        $issue = ['code' => $code, 'severity' => $severity, 'line_id' => $lineId, 'field' => $field];
        if (! in_array($issue, $this->issues, true)) {
            $this->issues[] = $issue;
        }
    }

    public function all(): array
    {
        return $this->issues;
    }

    public function codes(): array
    {
        return array_values(array_unique(array_column($this->issues, 'code')));
    }

    public function readiness(): string
    {
        if (in_array('incomplete', array_column($this->issues, 'severity'), true)) {
            return 'incomplete';
        }

        return $this->issues ? 'review_required' : 'ready';
    }
}
