<?php

namespace App\Ai;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, ?int $status = null)
    {
        // Never include provider response bodies, prompts, credentials or request URLs.
        parent::__construct('AI '.$reason.($status ? ' (HTTP '.$status.')' : ''));
    }
}
