<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Northwestern\SysDev\Chassis\Contracts\AccessTokenContract;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates API requests using Bearer token authentication.
 *
 * This middleware handles all the security-critical logic:
 * 1. Bearer token extraction and validation
 * 2. Token hash comparison
 * 3. IP-based access control (CIDR support)
 * 4. Usage tracking
 * 5. Context propagation for logging
 *
 * Extend this class and implement the abstract methods to wire up
 * your application's token and user models. Your token model must
 * implement AccessTokenContract.
 */
abstract class AuthenticatesAccessTokens
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        Context::add(ApiRequestContext::TRACE_ID, Str::uuid()->toString());

        $authHeader = (string) $request->header('Authorization', '');

        if (! str_starts_with($authHeader, 'Bearer ')) {
            $this->fail('invalid-header-format');
        }

        $rawToken = trim(Str::after($authHeader, 'Bearer '));

        if ($rawToken === '') {
            $this->fail('missing-credentials');
        }

        $tokenHash = $this->hashToken($rawToken);
        unset($rawToken);

        $token = $this->findActiveToken($tokenHash);

        if (! $token instanceof AccessTokenContract) {
            $this->fail('token-invalid-or-expired');
        }

        Context::add(ApiRequestContext::USER_ID, $token->getUserId());
        Context::add(ApiRequestContext::TOKEN_ID, $token->getTokenId());

        if (! $this->isIpAllowed($request->ip(), $token->getAllowedIps())) {
            $this->fail('ip-denied');
        }

        $token->recordUsage($request->ip());

        Auth::onceUsingId($token->getUserId());

        return $next($request);
    }

    /**
     * Find an active token by its hash.
     *
     * Return null if the token is not found, expired, or revoked.
     * Return the token model (implementing AccessTokenContract) if valid.
     */
    abstract protected function findActiveToken(string $tokenHash): ?AccessTokenContract;

    /**
     * Generate a hash for the given plain token.
     *
     * Typically delegates to your token model's static hashFromPlain() method.
     *
     * @param  non-empty-string  $plainToken  The plain token to hash.
     * @return non-empty-string The resulting hash.
     */
    abstract protected function hashToken(#[\SensitiveParameter] string $plainToken): string;

    /**
     * Check if the request IP is allowed by the token's IP allowlist.
     *
     * Supports individual IPs and CIDR notation. Override to customize
     * behavior when the request IP is missing (e.g. behind a proxy).
     *
     * @param  list<string>|null  $allowedIps
     */
    protected function isIpAllowed(?string $requestIp, ?array $allowedIps): bool
    {
        if ($allowedIps === null || $allowedIps === []) {
            return true;
        }

        if (blank($requestIp)) {
            $this->reportMissingIp($allowedIps);

            return false;
        }

        return IpUtils::checkIp($requestIp, $allowedIps);
    }

    /**
     * Called when a token has IP restrictions but the request IP is missing.
     *
     * Override to report this as an exception or log it.
     *
     * @param  list<string>  $allowedIps
     */
    protected function reportMissingIp(array $allowedIps): void
    {
        // Default: no-op. Override to report.
    }

    /**
     * Fail authentication with a reason.
     *
     * @throws AuthenticationException
     */
    protected function fail(string $reason): never
    {
        Context::add(ApiRequestContext::FAILURE_REASON, $reason);

        throw new AuthenticationException();
    }
}
