<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $production = app()->environment('production');
        $measure = app()->environment('local');
        $queryCount = 0;
        $queryMs = 0.0;
        if ($measure) {
            DB::listen(function ($query) use (&$queryCount, &$queryMs): void {
                $queryCount++;
                $queryMs += $query->time;
            });
        }
        if ($production) {
            config(['app.debug' => false]);
        }
        try {
            $configuredHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $hosts = $production ? [$configuredHost] : array_unique([$configuredHost, 'localhost', '127.0.0.1', '[::1]']);
            $healthProbe = $request->is('up') && in_array($request->getHost(), [$configuredHost, 'localhost', '127.0.0.1'], true);
            if (! $healthProbe && ! in_array(strtolower($request->getHost()), $hosts, true)) {
                $response = response('Host no permitido.', 400);
            } elseif ($production && $request->is('dev/*')) {
                $response = response('No encontrado.', 404);
            } elseif ($production && ! $healthProbe && (! str_starts_with((string) config('app.url'), 'https://') || ! config('session.secure') || ! config('session.http_only') || ! config('session.encrypt') || config('session.same_site') !== 'lax')) {
                $response = response('La configuración de seguridad del servicio requiere revisión.', 503);
            } elseif ($production && ! $healthProbe && ! $request->isSecure()) {
                $response = response('Este servicio requiere HTTPS.', 426);
            } else {
                $response = $next($request);
            }
        } catch (Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($measure && defined('LARAVEL_START')) {
            $response->headers->set('Server-Timing', sprintf('app;dur=%.1f, db;dur=%.1f;desc="%d queries"', (microtime(true) - LARAVEL_START) * 1000, $queryMs, $queryCount));
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        $response->headers->remove('X-Powered-By');
        if ($production && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($request->user() || $request->is('login', 'register', 'forgot-password', 'reset-password/*', 'reports*', 'commerce*', 'admin/*')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }
        if (in_array($response->getStatusCode(), [400, 401, 403, 419, 429], true)) {
            $fingerprint = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
            $key = 'security-log:'.$fingerprint.':'.$response->getStatusCode();
            if (! RateLimiter::tooManyAttempts($key, 10)) {
                RateLimiter::hit($key, 60);
                Log::channel('security')->notice('request_rejected', ['status' => $response->getStatusCode(), 'method' => $request->method(), 'route' => $request->route()?->getName() ?? 'unmatched', 'actor_id' => $request->user()?->id, 'client_fingerprint' => $fingerprint]);
            }
        }

        return $response;
    }
}
