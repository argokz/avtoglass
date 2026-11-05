<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\AssistantSearch;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\BuyerContext;
use App\Domain\Shared\PurchaseException;
use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Customer-owned, opt-in facts. Prices, stock and account privileges are never memory. */
final class MemoryService
{
    public function get(BuyerContext $buyer): ?CustomerMemory
    {
        $buyer = $buyer->fresh();

        return $buyer->customerId === null ? null : CustomerMemory::query()->where('customer_id', $buyer->customerId)->first();
    }

    public function consent(BuyerContext $buyer, bool $enabled): CustomerMemory
    {
        $buyer = $buyer->fresh();
        if ($buyer->customerId === null) {
            throw new PurchaseException('AUTH_REQUIRED', 401);
        }

        return DB::transaction(function () use ($buyer, $enabled) {
            // Serialize first creation with the existing customer row.
            DB::table('customers')->where('id', $buyer->customerId)->lockForUpdate()->first();
            $memory = CustomerMemory::query()->firstOrCreate(['customer_id' => $buyer->customerId], ['facts' => []]);
            $memory->forceFill(['consented_at' => $enabled ? now() : null, 'facts' => $enabled ? $memory->facts : [], 'version' => $memory->version + 1])->save();

            return $memory;
        });
    }

    public function save(BuyerContext $buyer, array $facts, int $expectedVersion, string $source = 'customer_form'): CustomerMemory
    {
        Validator::make($facts, [
            'vehicles' => ['sometimes', 'array', 'list', 'max:10'],
            'vehicles.*' => ['array:id,label,car_model_id,year,options'],
            'vehicles.*.id' => ['required', 'uuid', 'distinct'],
            'vehicles.*.label' => ['required', 'string', 'max:100'],
            'vehicles.*.car_model_id' => ['nullable', 'integer', 'exists:car_models,id'],
            'vehicles.*.year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 2)],
            'vehicles.*.options' => ['sometimes', 'array:'.implode(',', array_keys(Product::OPTION_FLAGS))],
            'vehicles.*.options.*' => ['nullable', 'boolean'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'fulfillment' => ['nullable', 'in:pickup,delivery,installation,visit'],
        ])->validate();
        if (array_diff(array_keys($facts), ['vehicles', 'city', 'address', 'fulfillment'])) {
            throw new PurchaseException('INVALID_MEMORY', 422);
        }
        foreach ($facts['vehicles'] ?? [] as $i => $vehicle) {
            $facts['vehicles'][$i]['year'] = isset($vehicle['year']) ? (int) $vehicle['year'] : null;
            if (empty($vehicle['car_model_id'])) {
                $model = app(SearchOffers::class)->resolveModel([], AssistantSearch::focusTerm($vehicle['label']));
                if ($model) {
                    $facts['vehicles'][$i]['car_model_id'] = $model->id;
                }
            } else {
                $facts['vehicles'][$i]['car_model_id'] = (int) $vehicle['car_model_id'];
            }
            $facts['vehicles'][$i]['options'] = array_map(fn ($value) => $value === null ? null : (bool) $value, $vehicle['options'] ?? []);
        }

        return DB::transaction(function () use ($buyer, $facts, $expectedVersion, $source) {
            $customerId = $buyer->fresh()->customerId;
            $memory = $customerId === null ? null : CustomerMemory::query()->where('customer_id', $customerId)->lockForUpdate()->first();
            if (! $memory?->consented_at) {
                throw new PurchaseException('MEMORY_CONSENT_REQUIRED', 403);
            }
            if ($memory->version !== $expectedVersion) {
                throw new PurchaseException('MEMORY_VERSION_CONFLICT', 409);
            }
            $values = [];
            foreach ($facts as $key => $value) {
                $previous = $memory->facts[$key] ?? null;
                $values[$key] = $previous && ($previous['value'] ?? null) === $value ? $previous
                    : ['value' => $value, 'source' => $source, 'confirmed_at' => now()->toIso8601String(), 'status' => 'confirmed'];
                if ($key === 'vehicles') {
                    $subjects = [];
                    foreach ($value as $vehicle) {
                        $fields = array_intersect_key($vehicle, array_flip(['label', 'car_model_id', 'year']));
                        foreach ($vehicle['options'] ?? [] as $flag => $answer) {
                            $fields['options.'.$flag] = $answer;
                        }
                        foreach ($fields as $field => $answer) {
                            $old = $previous['subjects'][$vehicle['id']][$field] ?? null;
                            $subjects[$vehicle['id']][$field] = $old && ($old['value'] ?? null) === $answer ? $old
                                : ['value' => $answer, 'source' => $source, 'confirmed_at' => now()->toIso8601String(), 'vehicle_id' => $vehicle['id'], 'status' => $answer === null ? 'unknown' : 'confirmed'];
                        }
                    }
                    $values[$key]['subjects'] = $subjects;
                }
            }
            $memory->forceFill(['facts' => $values, 'version' => $memory->version + 1])->save();

            return $memory;
        });
    }

    public function values(BuyerContext $buyer): array
    {
        $memory = $this->get($buyer);
        if (! $memory?->consented_at) {
            return [];
        }

        return collect($memory->facts)->map(fn ($fact) => $fact['value'] ?? null)->all();
    }

    public function remember(BuyerContext $buyer, array $intent, bool $temporary): void
    {
        if ($temporary || ! ($memory = $this->get($buyer))?->consented_at) {
            return;
        }
        $values = $this->values($buyer);
        foreach (['city', 'address', 'fulfillment'] as $field) {
            if (isset($intent[$field])) {
                $values[$field] = $intent[$field];
            }
        }
        if (isset($intent['car_model_id'], $intent['year'])) {
            $model = CarModel::query()->with('brand')->find($intent['car_model_id']);
            if ($model) {
                $vehicles = $values['vehicles'] ?? [];
                $index = collect($vehicles)->search(fn ($v) => ($v['car_model_id'] ?? null) === $model->id && ($v['year'] ?? null) === $intent['year']);
                $vehicle = ['id' => $index === false ? (string) Str::uuid() : $vehicles[$index]['id'],
                    'label' => trim($model->brand?->name.' '.$model->name), 'car_model_id' => $model->id, 'year' => $intent['year'], 'options' => array_replace($index === false ? [] : ($vehicles[$index]['options'] ?? []), $intent['options'] ?? [])];
                if ($index !== false) {
                    $vehicles[$index] = $vehicle;
                } elseif (count($vehicles) < 10) {
                    $vehicles[] = $vehicle;
                }
                $values['vehicles'] = $vehicles;
            }
        }
        try {
            $this->save($buyer, $values, $memory->version, 'customer_message');
        } catch (PurchaseException $e) {
            if ($e->errorCode !== 'MEMORY_VERSION_CONFLICT') {
                throw $e;
            }
            // A concurrent explicit cabinet edit wins over background personalization.
        }
    }
}
