<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Ensures a feature is enabled before processing requests.
 *
 * When the feature is disabled via configuration, all routes using this middleware
 * return a 503 Service Unavailable response, or a 404 Not Found response when the
 * second parameter is `404`, for a feature that should look absent while off.
 *
 * Usage in routes:
 * ```php
 * Route::middleware([EnsureFeatureEnabled::class . ':api.enabled'])->group(function () {
 *     // ...
 * });
 *
 * Route::middleware([EnsureFeatureEnabled::class . ':mcp.enabled,404'])->group(function () {
 *     // ...
 * });
 * ```
 */
class EnsureFeatureEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $configKey  The config key to check (passed as middleware parameter)
     * @param  string  $status  `503` (the default) or `404`, the response while the feature is disabled
     */
    public function handle(Request $request, Closure $next, string $configKey, string $status = '503'): Response
    {
        if (! config($configKey)) {
            throw $status === '404' ? new NotFoundHttpException() : new ServiceUnavailableHttpException();
        }

        return $next($request);
    }
}
