<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Requires the `X-Secret-Token` header to match a token held in configuration.
 *
 * A token endpoint must stay closed until a token is configured. Spatie Laravel Health's own
 * `RequiresSecretToken` middleware, for one, lets every request through while
 * `HEALTH_SECRET_TOKEN` is empty, which is how a fresh `.env` usually has it, so the endpoint
 * is public by default. This middleware answers 403 to every request while the configured
 * value is empty or not a string, and compares the header with `hash_equals()` once it is set.
 *
 * Usage in routes, with the config key holding the token as the parameter:
 * ```php
 * Route::get('/api/health', HealthCheckJsonResultsController::class)
 *     ->middleware(RequireSecretToken::class . ':health.secret_token');
 * ```
 */
class RequireSecretToken
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $configKey  The config key holding the token (passed as middleware parameter)
     */
    public function handle(Request $request, Closure $next, string $configKey = ''): Response
    {
        if ($configKey === '') {
            throw new InvalidArgumentException(self::class . ' needs the config key holding the token, as in `RequireSecretToken::class . \':health.secret_token\'`.');
        }

        $secretToken = config($configKey);

        if (! is_string($secretToken) || $secretToken === '') {
            throw new AccessDeniedHttpException('This endpoint is disabled until its secret token is configured.');
        }

        if (! hash_equals($secretToken, (string) $request->header('X-Secret-Token'))) {
            throw new AccessDeniedHttpException('Incorrect secret token.');
        }

        return $next($request);
    }
}
