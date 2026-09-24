<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сайт стоит не в корне домена, а в подпути — http://localhost/avto-glass.
 *
 * Laravel строит ссылки от корня и о префиксе не знает: route() отдаст
 * /catalog вместо /avto-glass/catalog, а @vite — /build/app.css, которого
 * по такому адресу нет. Выглядит это как страница без оформления со ссылками
 * в никуда, и по внешнему виду причину не угадать.
 *
 * Именно middleware, а не boot() провайдера: провайдер поднимается до того,
 * как приходит запрос, и заголовков там ещё нет.
 */
class ApplyForwardedPrefix
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = trim((string) $request->header('X-Forwarded-Prefix'), '/');

        /*
         * Заголовку верим только от прокси на этой же машине. Он приходит
         * снаружи и подменяется так же легко, как любой другой, а чужой адрес
         * в нём уводит ссылку из письма о сбросе пароля на сторонний сайт.
         */
        if ($prefix !== '' && in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            $scheme = strtolower((string) $request->header('X-Forwarded-Proto', $request->getScheme()));
            if (! in_array($scheme, ['http', 'https'], true)) {
                $scheme = $request->getScheme();
            }

            URL::forceScheme($scheme);
            URL::forceRootUrl($scheme.'://'.$request->getHttpHost().'/'.$prefix);
        }

        return $next($request);
    }
}
