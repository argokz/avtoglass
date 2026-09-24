<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Validation\Validator;

/**
 * Общие правила контактной формы.
 *
 * Карточка, корзина, подбор и онлайн-запись спрашивают одно и то же:
 * имя, телефон, ловушку для ботов и согласие на персональные данные.
 * Если правила разъедутся, запись начнёт принимать то, что обычная заявка
 * уже отвергает — а это как раз канал, который боты находят первым.
 */
class OrderContactRules
{
    /** @return array<string,list<string>> */
    public static function fields(bool $withSlot = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'website' => ['prohibited'],
        ];

        if (settings()->enabled('privacy_consent')) {
            $rules['privacy_consent'] = ['accepted'];
        }

        if ($withSlot) {
            $rules['chosen'] = ['required', 'string'];
        }

        return $rules;
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'Как к вам обращаться?',
            'name.min' => 'Имя слишком короткое',
            'phone.required' => 'Без телефона мы не сможем перезвонить',
            'address.required_if' => 'Для доставки и выезда нужен адрес',
            'website.prohibited' => 'Заявка не отправлена',
            'privacy_consent.accepted' => 'Чтобы оставить заявку, нужно согласие на обработку персональных данных',
            'chosen.required' => 'Выберите время записи',
        ];
    }

    /** @return array<string,string> */
    public static function attributes(): array
    {
        return [
            'name' => 'имя',
            'phone' => 'телефон',
            'privacy_consent' => 'согласие на обработку данных',
            'chosen' => 'время',
        ];
    }

    public static function assertPhone(Validator $validator, mixed $phone): void
    {
        $digits = Customer::normalizePhone(is_string($phone) ? $phone : null);

        if ($digits === null || mb_strlen($digits) < 10) {
            $validator->errors()->add('phone', 'Похоже, в номере не хватает цифр');
        }
    }
}
