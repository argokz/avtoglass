<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Оптовый раздел — только после одобрения менеджера.
 *
 * Розничный клиент и «заявка на опт на проверке» получают 403: цены и
 * спецификация опта им не положены, а молча редиректить — значит прятать
 * от человека, почему кнопка не работает.
 */
class OnlyWholesale
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::user()?->customer;

        if ($customer === null || ! $customer->seesWholesalePrices()) {
            abort(403, 'Оптовый раздел откроется после одобрения заявки менеджером.');
        }

        return $next($request);
    }
}
