<?php

namespace App\Http\Requests;

use App\Cart\Cart;
use App\Enums\FulfillmentType;
use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Оптовая спецификация: список позиций с количеством.
 *
 * Свои правила, а не StoreOrderRequest: у опта в форме нет имени и телефона
 * (это вошедший клиент), нет установки и нет выбора оплаты — способ оплаты
 * менеджер предложит после рассмотрения заявки.
 */
class StoreWholesaleOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // доступ уже отрезал OnlyWholesale
    }

    public function rules(): array
    {
        $rules = [
            'items' => ['required', 'array', 'min:1'],
            'items.*.offer_id' => ['required', 'integer', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.Cart::MAX_WHOLESALE_QUANTITY],
            'fulfillment' => ['required', Rule::in([FulfillmentType::Delivery->value, FulfillmentType::Pickup->value])],
            'address' => ['nullable', 'string', 'max:255', 'required_if:fulfillment,'.FulfillmentType::Delivery->value],
            'comment' => ['nullable', 'string', 'max:2000'],
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
        return [
            'items.required' => 'Отметьте хотя бы одну позицию',
            'items.min' => 'Отметьте хотя бы одну позицию',
            'items.*.offer_id.required' => 'Позиция без предложения — обновите страницу',
            'items.*.offer_id.distinct' => 'Одна позиция встречается дважды — поправьте количество в ней',
            'items.*.qty.required' => 'Укажите количество',
            'items.*.qty.min' => 'Количество — от одной штуки',
            'items.*.qty.max' => 'Больше '.Cart::MAX_WHOLESALE_QUANTITY.' штук одной позиции — через менеджера',
            'fulfillment.required' => 'Выберите: доставка или самовывоз',
            'fulfillment.in' => 'У оптовой заявки нет установки — только доставка или самовывоз',
            'address.required_if' => 'Для доставки нужен адрес',
            'privacy_consent.accepted' => 'Чтобы отправить заявку, нужно согласие на обработку персональных данных',
            'website.prohibited' => 'Заявка не отправлена',
        ];
    }

    /**
     * Позиции сверяем с базой: оффер живой, товар на витрине.
     * Пороги — из настроек CRM, по умолчанию выключены (0 — любая заявка).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $customer = $this->user()?->customer;
            $rows = collect($this->input('items', []));

            $offers = Offer::query()
                ->whereIn('id', $rows->pluck('offer_id'))
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('is_published', true))
                ->with('product')
                ->get()
                ->keyBy('id');

            $total = 0.0;
            $qty = 0;

            foreach ($rows as $index => $row) {
                $offer = $offers->get((int) $row['offer_id']);

                if ($offer === null) {
                    $validator->errors()->add("items.{$index}.offer_id", 'Позиция ушла с витрины — обновите страницу');

                    continue;
                }

                $price = $offer->sellingPrice($customer);
                $total += ($price ?? 0) * (int) $row['qty'];
                $qty += (int) $row['qty'];
            }

            $minTotal = (int) settings()->get('wholesale_min_total', 0);
            $minQty = (int) settings()->get('wholesale_min_qty', 0);

            if ($minTotal > 0 && $total < $minTotal) {
                $validator->errors()->add('items', 'Минимальная сумма оптовой заявки — '.number_format($minTotal, 0, ',', ' ').' ₸');
            }

            if ($minQty > 0 && $qty < $minQty) {
                $validator->errors()->add('items', "Минимальное количество в оптовой заявке — {$minQty} шт");
            }
        });
    }
}
