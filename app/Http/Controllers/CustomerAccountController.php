<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Личный кабинет покупателя: регистрация, вход, выход.
 *
 * Аккаунт здесь — удобство, а не пропуск: заказ оформляется и без него,
 * и так и должно оставаться. Человек с разбитым стеклом стоит на улице,
 * и форма «придумайте пароль» между ним и заявкой — это потерянный заказ.
 *
 * Вход по телефону, а не по почте: номер покупатель помнит, почту заводят
 * не все, а в заявке всё равно нужен телефон, чтобы перезвонить.
 */
class CustomerAccountController extends Controller
{
    public function registerForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('account.orders');
        }

        $prefillName = null;
        $prefillPhone = null;

        $number = session('last_order_number');

        if (is_string($number) && $number !== '') {
            $order = Order::where('number', $number)->with('customer')->first();
            $prefillName = $order?->customer?->name;
            $prefillPhone = $order?->customer?->phone;
        }

        return view('account.register', [
            'prefillName' => $prefillName,
            'prefillPhone' => $prefillPhone,
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate(
            [
                'name' => ['required', 'string', 'max:120'],
                'phone' => ['required', 'string', 'max:32'],
                'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::min(8)],
                'website' => ['prohibited'],
                'wholesale' => ['sometimes', 'boolean'],
                'company_name' => ['nullable', 'required_if:wholesale,1', 'string', 'max:190'],
                'bin' => ['nullable', 'regex:/^\d{12}$/'],
            ],
            [
                'name.required' => 'Как к вам обращаться?',
                'phone.required' => 'Телефон нужен, чтобы перезвонить по заявке',
                'password.confirmed' => 'Пароли не совпадают',
                'email.unique' => 'На эту почту аккаунт уже есть',
                'website.prohibited' => 'Не получилось зарегистрировать',
                'company_name.required_if' => 'Для опта нужно название организации',
                'bin.regex' => 'БИН или ИИН — 12 цифр',
            ],
        );

        $phone = Customer::normalizePhone($data['phone']);

        if ($phone === null || mb_strlen($phone) < 10) {
            return back()->withInput()->withErrors(['phone' => 'Проверьте номер телефона']);
        }

        if ($this->accountExists($phone)) {
            return back()->withInput()->withErrors([
                'phone' => 'На этот номер уже есть кабинет. Войдите или восстановите доступ по заявке.',
            ]);
        }

        $user = DB::transaction(function () use ($data, $phone, $request) {
            $customer = Customer::where('phone_hash', Customer::phoneHash($phone))->first();

            /*
             * Ключевое место. Зарегистрироваться можно на любой номер — SMS мы
             * не подтверждаем. Поэтому карточку с чужой историей заказов
             * аккаунт не получает: она привяжется только после того, как
             * человек назовёт номер одной из своих заявок.
             *
             * Новый номер и карточка без заказов ничего не раскрывают,
             * их привязываем сразу.
             */
            $безопасноПривязать = $customer === null || $customer->orders()->doesntExist();

            if ($customer === null) {
                $customer = Customer::forPhone($phone, $data['name']);
            }

            if ($request->boolean('wholesale') && $безопасноПривязать) {
                $customer->requestWholesale((string) ($data['company_name'] ?? ''), $data['bin'] ?? null);
            }

            return User::create([
                'name' => $data['name'],
                // nullable-поле может не прийти вовсе, а не прийти пустым
                'email' => ($data['email'] ?? null) ?: null,
                'phone' => $phone,
                'password' => Hash::make($data['password']),
                'role' => UserRole::Customer,
                'is_active' => true,
                'customer_id' => $безопасноПривязать ? $customer->id : null,
            ]);
        });

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        if ($user->customer_id === null) {
            return redirect()->route('account.claim')
                ->with('status', 'Кабинет создан. По этому номеру у нас уже есть заявки — назовите номер любой из них, чтобы открыть историю.');
        }

        $status = $request->boolean('wholesale')
            ? 'Кабинет создан. Заявку на опт отправили менеджеру — пока цены розничные.'
            : 'Кабинет создан.';

        return redirect()->route('account.orders')->with('status', $status);
    }

    public function loginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('account.orders');
        }

        return view('account.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(
            [
                'phone' => ['required', 'string', 'max:32'],
                'password' => ['required', 'string'],
                'website' => ['prohibited'],
            ],
            [
                'phone.required' => 'Введите телефон',
                'password.required' => 'Введите пароль',
                'website.prohibited' => 'Не получилось войти',
            ],
        );

        $phone = Customer::normalizePhone($data['phone']) ?? '';

        $ok = Auth::attempt([
            'phone' => $phone,
            'password' => $data['password'],
            'role' => UserRole::Customer->value,
            'is_active' => true,
        ], remember: true);

        if (! $ok) {
            /*
             * Одна формулировка на оба случая: разные тексты для «нет такого
             * номера» и «пароль не тот» позволяют перебором узнать, кто у нас
             * обслуживается.
             */
            return back()->withInput()->withErrors(['phone' => 'Не подошли телефон или пароль']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('account.orders'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /** Есть ли уже кабинет на этот номер. */
    private function accountExists(string $phone): bool
    {
        return User::where('role', UserRole::Customer)->where('phone', $phone)->exists();
    }
}
