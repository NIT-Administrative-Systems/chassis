<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Ensures a feature is enabled before processing requests.
 *
 * When the feature is disabled via configuration, all routes using this middleware
 * will return a 503 Service Unavailable response.
 *
 * Usage in routes:
 * ```php
 * Route::middleware([EnsureFeatureEnabled::class . ':api.enabled'])->group(function () {
 *     // ...
 * });
 * ```
 */
class EnsureFeatureEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $configKey  The config key to check (passed as middleware parameter)
     */
    public function handle(Request $request, Closure $next, string $configKey): Response
    {
        if (! config($configKey)) {
            throw new ServiceUnavailableHttpException();
        }

        return $next($request);
    }
}
