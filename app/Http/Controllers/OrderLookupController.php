<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Заявки клиента без аккаунта.
 *
 * Покупателю не нужен логин: он помнит телефон и номер АС-000123,
 * который назвали по звонку. Оба сразу — иначе по одному номеру
 * любой листал бы чужие заявки, а по одному телефону — все заказы
 * человека, чей номер утёк из мессенджера.
 */
class OrderLookupController extends Controller
{
    public const SESSION_PHONE = 'customer_phone';

    public function form(): View|RedirectResponse
    {
        if (session(self::SESSION_PHONE)) {
            return redirect()->route('orders.mine');
        }

        return view('order.lookup');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate(
            [
                'phone' => ['required', 'string', 'max:32'],
                'number' => ['required', 'string', 'max:24'],
                'website' => ['prohibited'],
            ],
            [
                'phone.required' => 'Без телефона заявку не найти',
                'number.required' => 'Нужен номер заявки — его называли при оформлении',
                'website.prohibited' => 'Заявка не найдена',
            ],
        );

        $phone = Customer::normalizePhone($data['phone']);
        $number = Order::normalizeNumber($data['number']);

        if ($phone === null || mb_strlen($phone) < 10 || $number === '') {
            return $this->notFound();
        }

        $order = Order::query()
            ->where('number', $number)
            ->whereHas('customer', fn ($query) => $query->where('phone_hash', Customer::phoneHash($phone)))
            ->first();

        if ($order === null) {
            return $this->notFound();
        }

        session([self::SESSION_PHONE => $phone]);

        return redirect()->route('orders.mine');
    }

    public function mine(): View|RedirectResponse
    {
        $phone = session(self::SESSION_PHONE);

        if (! is_string($phone) || $phone === '') {
            return redirect()->route('orders.lookup');
        }

        $customer = Customer::query()->where('phone_hash', Customer::phoneHash($phone))->first();
        $orders = $customer
            ? $customer->orders()->with('items')->latest()->get()
            : collect();

        return view('order.mine', [
            'customer' => $customer,
            'orders' => $orders,
        ]);
    }

    public function logout(): RedirectResponse
    {
        session()->forget(self::SESSION_PHONE);

        return redirect()->route('orders.lookup');
    }

    private function notFound(): RedirectResponse
    {
        return back()
            ->withInput()
            ->withErrors([
                'number' => 'Не нашли заявку с таким номером и телефоном. Проверьте или позвоните.',
            ]);
    }
}
