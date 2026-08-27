<?php

namespace App\Payments;

use App\Enums\PaymentStatus;

/** Разобранное уведомление кассы, приведённое к нашим понятиям. */
readonly class CallbackResult
{
    public function __construct(
        public string $token,
        public PaymentStatus $status,
        public float $amount,
        public ?string $externalId = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
