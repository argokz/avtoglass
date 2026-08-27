<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentType;
use App\Models\Customer;
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
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'car_info' => ['nullable', 'string', 'max:200'],
            'fulfillment' => ['nullable', Rule::enum(FulfillmentType::class)],
            'address' => ['nullable', 'string', 'max:255', 'required_if:fulfillment,delivery'],
            // Ловушка для ботов: настоящий человек это поле не видит и не заполнит
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Как к вам обращаться?',
            'name.min' => 'Имя слишком короткое',
            'phone.required' => 'Без телефона мы не сможем перезвонить',
            'address.required_if' => 'Для доставки нужен адрес',
            'website.prohibited' => 'Заявка не отправлена',
        ];
    }

    public function withValidator($validator): void
    {
        // Телефон проверяем по числу цифр, а не по маске: люди пишут его
        // десятком разных способов, и отказ из-за скобок раздражает зря.
        $validator->after(function ($validator) {
            $digits = Customer::normalizePhone($this->input('phone'));

            if ($digits === null || mb_strlen($digits) < 10) {
                $validator->errors()->add('phone', 'Похоже, в номере не хватает цифр');
            }
        });
    }
}
