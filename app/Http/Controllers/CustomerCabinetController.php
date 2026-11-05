<?php

namespace App\Http\Controllers;

use App\Actions\StartPayment;
use App\Ai\OrderReplies;
use App\Ai\Runtime\ChatIdentity;
use App\Cart\Cart;
use App\Domain\Ordering\CustomerPurchases;
use App\Domain\Ordering\PaymentOptions;
use App\Domain\Shared\PurchaseException;
use App\Enums\WholesaleStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Кабинет покупателя: свои заявки, профиль и привязка прошлых заказов.
 *
 * Заявки берутся не по телефону, а по привязанной карточке. Разница
 * существенная: телефон в форме регистрации может указать кто угодно,
 * а карточку с чужой историей аккаунт получает только после того, как
 * человек назвал номер своей заявки.
 */
class CustomerCabinetController extends Controller
{
    public function orders(): View
    {
        $user = Auth::user();

        $orders = Order::query()
            ->where(fn ($query) => $query->where('customer_id', $user->customer_id)
                ->orWhereIn('id', DB::table('purchase_access_grants')->where('customer_id', $user->customer_id)->select('order_id')))
            ->when($user->customer_id === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->with(['items', 'payments'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(20);

        $purchases = app(CustomerPurchases::class);
        $payments = app(StartPayment::class);
        // Кнопка «Оплатить» только там, где касса её примет: те же правила, что у purchase.pay.
        // Кабинет без привязанной карточки заказов не видит — и оплачивать ему нечего.
        $online = $user->customer_id === null ? [] : array_keys(PaymentOptions::available(ChatIdentity::buyer()));

        return view('account.orders', [
            'user' => $user,
            'orders' => $orders,
            // Что можно сделать с заказом прямо из списка — считаем здесь, а не в шаблоне.
            'actions' => $orders->getCollection()->mapWithKeys(fn (Order $order) => [$order->id => [
                'pay' => in_array($order->payment_method?->value, $online, true)
                    && PaymentOptions::driver($order->payment_method->value) !== null && $payments->isPayable($order),
                'cancel' => $purchases->cancelBlocker($order) === null,
                'reorder' => $order->items->contains(fn ($item) => $item->product_id !== null),
            ]])->all(),
            'естьЧтоПривязать' => $user->customer_id === null && $this->прошлыеЗаявки($user->phone),
        ]);
    }

    public function cancel(Request $request, string $token, CustomerPurchases $purchases): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        try {
            $order = $purchases->cancel(ChatIdentity::buyer(), $token, (string) ($data['reason'] ?? ''));
        } catch (PurchaseException $exception) {
            abort_if($exception->errorCode === 'RESOURCE_NOT_FOUND', 404);

            return back()->with('status', OrderReplies::cancel(['order' => ['number' => ''], 'cancel' => ['code' => $exception->errorCode]], false));
        }

        return back()->with('status', "Заказ {$order->number} отменён. Менеджер получил уведомление.");
    }

    /**
     * «Заказать снова» для розницы: позиции прошлого заказа — в корзину.
     *
     * Цены берём сегодняшние, а не из старого заказа: обещать прошлую цену
     * мы не можем, а корзина и так считает по текущей. Чего больше нет на
     * витрине, не кладём и прямо об этом говорим.
     */
    public function reorder(string $token, CustomerPurchases $purchases, Cart $cart): RedirectResponse
    {
        try {
            $order = $purchases->owned(ChatIdentity::buyer(), $token);
        } catch (PurchaseException) {
            abort(404);
        }
        $added = 0;
        $skipped = [];
        foreach ($order->items()->whereNotNull('product_id')->with('product')->get() as $item) {
            if ($item->product === null || ! $item->product->is_published) {
                $skipped[] = $item->name;

                continue;
            }
            $cart->add($item->product, max(1, (int) $item->quantity), $item->offer_id);
            $added++;
        }
        if ($added === 0) {
            return back()->with('status', 'Позиций из этого заказа сейчас нет на витрине. Позвоните — подберём замену.');
        }

        return redirect()->route('cart.show')->with('success', $skipped === []
            ? 'Позиции заказа '.$order->number.' в корзине. Цены — сегодняшние.'
            : 'Добавили то, что есть сейчас. Нет на витрине: '.implode(', ', $skipped).'.');
    }

    public function profile(): View
    {
        $user = Auth::user();
        $customer = $user->customer;

        return view('account.profile', [
            'user' => $user,
            'customer' => $customer,
        ]);
    }

    /**
     * Обновляет имя, почту, адрес доставки и (опционально) пароль.
     *
     * Телефон здесь не меняем: это логин. Смена номера — через менеджера,
     * иначе человек мог бы «переехать» на чужую историю заказов.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $data = $request->validate(
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => [
                    'nullable',
                    'email',
                    'max:190',
                    Rule::unique('users', 'email')->ignore($user->id),
                ],
                'default_address' => ['nullable', 'string', 'max:255'],
                'default_city' => ['nullable', 'string', 'max:64'],
                'vin' => ['nullable', 'string', 'max:24'],
                'car_plate' => ['nullable', 'string', 'max:16'],
                'password' => ['nullable', 'confirmed', Password::min(8)],
                'wholesale' => ['sometimes', 'boolean'],
                'company_name' => ['nullable', 'required_if:wholesale,1', 'string', 'max:190'],
                'bin' => ['nullable', 'regex:/^\d{12}$/'],
            ],
            [
                'name.required' => 'Как к вам обращаться?',
                'email.unique' => 'На эту почту уже есть аккаунт',
                'password.confirmed' => 'Пароли не совпадают',
                'company_name.required_if' => 'Для опта нужно название организации',
                'bin.regex' => 'БИН или ИИН — 12 цифр',
            ],
        );

        $user->forceFill([
            'name' => $data['name'],
            'email' => ($data['email'] ?? null) ?: null,
        ])->save();

        if (! empty($data['password'])) {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
        }

        $customer = $user->customer ?? Customer::forPhone($user->phone, $data['name']);

        if ($user->customer_id === null) {
            // Пустая карточка без заказов — можно привязать. Если заказы есть,
            // не трогаем: пусть человек сначала докажет номер заявки.
            if ($customer->orders()->doesntExist()) {
                $user->forceFill(['customer_id' => $customer->id])->save();
            }
        }

        if ($user->fresh()->customer_id === $customer->id) {
            $customer->update([
                'name' => $data['name'],
                'email' => ($data['email'] ?? null) ?: $customer->email,
                'default_address' => trim((string) ($data['default_address'] ?? '')) ?: null,
                'default_city' => trim((string) ($data['default_city'] ?? '')) ?: null,
                'vin' => trim((string) ($data['vin'] ?? '')) ?: null,
                'car_plate' => trim((string) ($data['car_plate'] ?? '')) ?: null,
            ]);

            if ($request->boolean('wholesale') && ! $customer->seesWholesalePrices()) {
                $customer->requestWholesale((string) ($data['company_name'] ?? ''), $data['bin'] ?? null);
            } elseif ($customer->company_name || $customer->seesWholesalePrices() || $customer->wholesale_status === WholesaleStatus::Pending) {
                $customer->update([
                    'company_name' => trim((string) ($data['company_name'] ?? '')) ?: $customer->company_name,
                    'bin' => preg_replace('/\D+/', '', (string) ($data['bin'] ?? '')) ?: $customer->bin,
                ]);
            }
        }

        return redirect()->route('account.profile')
            ->with('status', 'Профиль сохранён.');
    }

    public function claimForm(): View|RedirectResponse
    {
        if (Auth::user()->customer_id !== null) {
            return redirect()->route('account.orders');
        }

        return view('account.claim');
    }

    /**
     * Привязывает к кабинету карточку с прошлыми заказами.
     *
     * Доказательство — номер заявки: его называют при оформлении и по
     * телефону, и знает его тот, кто заказ делал. Телефона одного мало,
     * иначе историю получил бы любой, кто этот номер где-то видел.
     */
    public function claim(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['number' => ['required', 'string', 'max:24']],
            ['number.required' => 'Нужен номер заявки — его называли при оформлении'],
        );

        $user = Auth::user();
        $phone = $user->phone;
        $number = Order::normalizeNumber($data['number']);

        $order = Order::query()
            ->where('number', $number)
            ->whereHas('customer', fn ($query) => $query->where('phone_hash', Customer::phoneHash($phone)))
            ->first();

        if ($order === null) {
            return back()->withInput()->withErrors([
                'number' => 'Не нашли заявку с таким номером на ваш телефон. Проверьте номер или позвоните нам.',
            ]);
        }

        $user->forceFill(['customer_id' => $order->customer_id])->save();

        return redirect()->route('account.orders')
            ->with('status', 'История заявок открыта.');
    }

    /** Есть ли на этот телефон заказы, которых кабинет пока не показывает. */
    private function прошлыеЗаявки(?string $phone): bool
    {
        if (! is_string($phone) || $phone === '') {
            return false;
        }

        $customer = Customer::where('phone_hash', Customer::phoneHash($phone))->first();

        return $customer !== null && $customer->orders()->exists();
    }
}
