<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Экспресс-покупка «в 1 клик»: только телефон и согласие.
 *
 * Имя здесь не спрашивают намеренно — каждое лишнее поле на этом шаге
 * режет конверсию, а как обращаться к человеку менеджер уточнит при звонке.
 * Ловушка для ботов и лимит частоты — те же, что у обычной заявки.
 */
class QuickOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'phone' => ['required', 'string', 'max:32'],
            'form_token' => ['nullable', 'string', 'max:64'],
            'website' => ['prohibited'],
        ];

        if (settings()->enabled('privacy_consent')) {
            $rules['privacy_consent'] = ['accepted'];
        }

        return $rules;
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
