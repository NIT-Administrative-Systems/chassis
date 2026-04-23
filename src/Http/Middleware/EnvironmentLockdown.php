<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts application access in non-production environments.
 *
 * Works out of the box (disabled by default). Override the protected
 * methods to define your app's lockdown behavior.
 *
 * ```php
 * class EnvironmentLockdown extends \Northwestern\SysDev\Chassis\Http\Middleware\EnvironmentLockdown
 * {
 *     protected function isEnabled(): bool
 *     {
 *         return (bool) config('platform.lockdown.enabled');
 *     }
 *
 *     protected function isAuthorized(Request $request): bool
 *     {
 *         return $request->user()->isImpersonated()
 *             || $request->user()->non_default_roles->isNotEmpty();
 *     }
 *
 *     protected function redirectRoute(): string
 *     {
 *         return 'platform.environment-lockdown';
 *     }
 * }
 * ```
 */
class EnvironmentLockdown
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isEnabled()) {
            return $next($request);
        }

        if (! $request->user()) {
            return $next($request);
        }

        if ($this->isAuthorized($request)) {
            return $next($request);
        }

        if ($this->isExemptedRoute($request)) {
            return $next($request);
        }

        return redirect()->route($this->redirectRoute());
    }

    /**
     * Whether lockdown is currently active.
     *
     * Override to enable lockdown based on your config.
     * Default: disabled.
     */
    protected function isEnabled(): bool
    {
        return false;
    }

    /**
     * Whether the current request's user should be allowed through.
     *
     * Override to define your authorization logic.
     * Default: allow everyone.
     */
    protected function isAuthorized(Request $request): bool
    {
        return true;
    }

    /**
     * The named route to redirect unauthorized users to.
     *
     * Override to point to your lockdown page.
     */
    protected function redirectRoute(): string
    {
        return 'lockdown';
    }

    /**
     * Route name patterns that bypass lockdown (e.g. auth routes).
     *
     * @return list<string>
     */
    protected function exemptedRoutePatterns(): array
    {
        return [];
    }

    /**
     * Whether the current request matches an exempted route pattern.
     *
     * Override to extend exemption strategies (e.g. add IP-based checks
     * while still reusing the pattern match).
     */
    protected function isExemptedRoute(Request $request): bool
    {
        $patterns = $this->exemptedRoutePatterns();

        return $patterns !== [] && $request->routeIs(...$patterns);
    }
}
