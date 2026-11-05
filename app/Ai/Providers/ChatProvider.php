<?php

namespace App\Ai\Providers;

use App\Ai\AiRequestBudget;

interface ChatProvider
{
    public function generate(array $contents, ?string $system = null, array $functions = [], ?AiRequestBudget $budget = null, ?callable $onChunk = null): array;
}
