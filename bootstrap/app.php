<?php

use App\Http\Middleware\ApplyForwardedPrefix;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\OrderingApiBoundary;
use App\Http\Ordering\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Сайт может стоять не в корне домена, а в подпути — /avto-glass.
         * Об этом сообщает nginx заголовком, и ссылки надо строить с ним.
         * Первым в цепочке: правильные адреса нужны всем, кто идёт следом,
         * включая редиректы гостя на страницу входа чуть ниже.
         */
        $middleware->prepend([ApplyForwardedPrefix::class, OrderingApiBoundary::class]);

        // CSP поверх всех веб-ответов. По умолчанию Report-Only: смотрим
        // нарушения, ничего не ломая; боевой режим — CSP_ENFORCE=true.
        $middleware->appendToGroup('web', [ContentSecurityPolicy::class]);

        /*
         * Nginx на этой же машине ставит X-Forwarded-Proto. Без доверия
         * к 127.0.0.1 Laravel считает запрос http — и на https://itwin.kz
         * страница приходит с http-ссылками на стили, браузер их режет.
         */
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        /*
         * Входов два, и путать их нельзя: покупатель входит по телефону на
         * сайте, сотрудник — по почте в панели. Гостя отправляем туда, куда
         * он шёл: на кабинет — к клиентскому входу, на всё остальное —
         * в админку. Без этого правила гость получал бы 500 «Route [login]
         * not defined».
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('kabinet*') || $request->is('opt*')
            ? route('account.login')
            : route('filament.admin.auth.login'));

        // Уже вошедшего покупателя со страниц входа и регистрации — в кабинет.
        $middleware->redirectUsersTo(fn () => route('account.orders'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/v1', 'api/v1/*')) {
                return app(ApiResponse::class)->error($request, $exception);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
