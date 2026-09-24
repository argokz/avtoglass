<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Кабинет — для покупателей.
 *
 * Сотрудник, вошедший в панель, авторизован тем же guard, и без этой проверки
 * менеджер попадал бы в клиентский кабинет — пустой, потому что карточки
 * покупателя у него нет. Это не дыра в правах, но выглядит как поломка,
 * а объяснять её будет он же.
 */
class OnlyCustomers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->isStaff()) {
            return redirect()->route('home');
        }

        return $next($request);
    }
}
