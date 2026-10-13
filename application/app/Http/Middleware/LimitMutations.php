<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class LimitMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $identity = $request->user() ? 'user:'.$request->user()->id : 'ip:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
            $key = 'web-mutations:'.$identity;
            if (RateLimiter::tooManyAttempts($key, (int) config('security.write_limit', 120))) {
                return response('Demasiadas solicitudes. Intenta de nuevo en un minuto.', 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, 60);
        }

        return $next($request);
    }
}
