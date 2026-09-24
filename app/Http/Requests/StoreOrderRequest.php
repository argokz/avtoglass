<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentType;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(OrderContactRules::fields(), [
            'form_token' => ['nullable', 'string', 'max:64'],
            'car_info' => ['nullable', 'string', 'max:200'],
            'fulfillment' => ['nullable', Rule::enum(FulfillmentType::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'address' => ['nullable', 'string', 'max:255', 'required_if:fulfillment,delivery,visit'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_time' => ['nullable', 'string', 'max:40'],
        ]);
    }

    public function messages(): array
    {
        return OrderContactRules::messages();
    }

    public function attributes(): array
    {
        return OrderContactRules::attributes();
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            OrderContactRules::assertPhone($validator, $this->input('phone'));
        });
    }
}
