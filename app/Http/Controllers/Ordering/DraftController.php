<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ordering;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Ordering\SalesMode;
use App\Domain\Pricing\QuoteDraft;
use App\Domain\Shared\Validation\InputType;
use App\Http\Ordering\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DraftController
{
    public function __construct(private readonly PurchaseCommands $commands, private readonly ApiResponse $responses) {}

    public function session(Request $request)
    {
        return $this->responses->success($request, ['csrf_token' => $request->session()->token(),
            'locale' => $this->buyer($request)->locale, 'authenticated' => $this->buyer($request)->actorId !== null]);
    }

    public function create(Request $request)
    {
        $payload = $this->payload($request, ['sales_mode']);
        $data = Validator::make($payload, ['sales_mode' => ['sometimes', 'required', Rule::enum(SalesMode::class)]])->validate();

        return $this->responses->success($request, $this->commands->createDraft($this->buyer($request), $this->key($request), SalesMode::from($data['sales_mode'] ?? 'retail')));
    }

    public function get(Request $request, string $draft, DraftService $drafts)
    {
        return $this->responses->success($request, $drafts->get($this->buyer($request), $draft)->jsonSerialize());
    }

    public function update(Request $request, string $draft)
    {
        $payload = $this->payload($request, ['expected_version', 'changes']);
        $data = Validator::make($payload, [
            'expected_version' => ['required', InputType::integer(), 'integer', 'between:1,2147483647'],
            'changes' => ['required', InputType::array(), 'array'],
        ])->validate();

        return $this->responses->success($request, $this->commands->updateDraft($this->buyer($request), $draft, $this->key($request), $data['expected_version'], $data['changes']));
    }

    public function quote(Request $request, string $draft)
    {
        $payload = $this->payload($request, ['expected_version']);
        $data = Validator::make($payload, ['expected_version' => ['required', InputType::integer(), 'integer', 'between:1,2147483647']])->validate();

        return $this->responses->success($request, $this->commands->prepareQuote($this->buyer($request), $draft, $this->key($request), $data['expected_version']));
    }

    public function submit(Request $request, string $draft)
    {
        $payload = $this->payload($request, ['quote_id', 'expected_version', 'action']);
        $data = Validator::make($payload, [
            'quote_id' => ['required', 'uuid'],
            'expected_version' => ['required', InputType::integer(), 'integer', 'between:1,2147483647'],
            'action' => ['required', 'string', Rule::in(['confirm_retail', 'submit_for_review'])],
        ])->validate();

        return $this->responses->success($request, $this->commands->submitDraft(
            $this->buyer($request),
            $draft,
            $this->key($request),
            $data['quote_id'],
            $data['expected_version'],
            $data['action'],
        ));
    }

    public function getQuote(Request $request, string $draft, string $quote, QuoteDraft $quotes)
    {
        return $this->responses->success($request, $quotes->get($this->buyer($request), $draft, $quote)->jsonSerialize());
    }

    private function buyer(Request $request): BuyerContext
    {
        return $request->attributes->get('ordering.buyer');
    }

    private function key(Request $request): string
    {
        return (string) $request->header('Idempotency-Key', '');
    }

    private function payload(Request $request, array $allowed): array
    {
        $payload = $request->attributes->get('ordering.payload', []);
        if (array_diff(array_keys($payload), $allowed)) {
            throw ValidationException::withMessages(['_input' => 'UNEXPECTED_FIELDS']);
        }

        return $payload;
    }
}
