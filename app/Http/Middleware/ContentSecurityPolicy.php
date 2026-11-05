<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy в режиме Report-Only.
 *
 * Политика намеренно мягкая: на страницах живут инлайн-скрипты Vite/Livewire
 * и виджет кассы Halyk, и жёсткий CSP сломал бы их молча. Report-Only ничего
 * не блокирует, но браузеры шлют нарушения — по ним политику ужесточают,
 * не рискуя витриной. Перевод в боевой режим — через CSP_ENFORCE=true после
 * чистого отчётного периода.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->isSuccessful() && ! $response->isRedirection()) {
            return $response;
        }

        $policy = implode('; ', [
            "default-src 'self'",
            // unsafe-inline: инлайн-скрипты layout, Livewire и виджет кассы.
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);

        $header = config('security.csp_enforce')
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, $policy);

        return $response;
    }
}
