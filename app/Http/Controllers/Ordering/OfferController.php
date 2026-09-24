<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ordering;

use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\BuyerContext;
use App\Http\Ordering\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OfferController
{
    public function __construct(private readonly SearchOffers $search, private readonly ApiResponse $responses) {}

    public function index(Request $request)
    {
        $allowed = [
            'q', 'page', 'per_page', 'glass_type', 'supplier_id', 'in_stock_only',
            'car_model_id', 'brand_id', 'year', 'generation_id', 'body', 'doors', 'options',
        ];
        $params = $request->query();
        if ($unknown = array_diff(array_keys($params), $allowed)) {
            throw ValidationException::withMessages([
                array_values($unknown)[0] => ['unexpected'],
            ]);
        }

        // Query strings arrive as strings; SearchOffers validates types strictly.
        if (array_key_exists('options', $params) && is_string($params['options'])) {
            $decoded = json_decode($params['options'], true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['options' => ['invalid']]);
            }
            $params['options'] = $decoded;
        }

        $result = $this->search->search($this->buyer($request), $params);

        return $this->responses->page($request, $result['items'], $result['meta']);
    }

    private function buyer(Request $request): BuyerContext
    {
        return $request->attributes->get('ordering.buyer');
    }
}
