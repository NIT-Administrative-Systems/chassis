<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\Guards\TokenGuard;
use Laravel\Passport\PassportUserProvider;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Northwestern\SysDev\Chassis\Enums\ApiPrincipalType;
use Northwestern\SysDev\Chassis\Enums\OAuthGrantType;
use Northwestern\SysDev\Chassis\Http\Middleware\Concerns\ChecksAllowedIps;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates API requests that carry a Laravel Passport access token, in place of
 * Passport's `auth:api` middleware.
 *
 * Unlike `auth:api`, it records every request for logging, including failures and
 * client-credentials tokens:
 * 1. Bearer token validation through Passport's resource server, for every grant
 * 2. A failure reason in the request context when authentication fails
 * 3. The principal, client, token ID, scopes and grant type in the request context
 * 4. Client credentials acting as the client's owner, when it has one that can sign in
 * 5. Application hooks for client IP allowlists, account eligibility and usage recording
 *
 * It then sets the user and client on Passport's guard and makes it the default guard,
 * so `$request->user()`, Passport's scope middleware and policies work as after `auth:api`.
 *
 * Extend this class and override the hooks your application needs:
 *
 * ```php
 * class AuthenticatePassportToken extends AuthenticatesPassportTokens
 * {
 *     protected function allowedIps(Client $client): ?array
 *     {
 *         return $client->allowed_ips;
 *     }
 *
 *     protected function isEligible(Authenticatable $user): bool
 *     {
 *         return ! $user->netid_inactive;
 *     }
 * }
 * ```
 */
abstract class AuthenticatesPassportTokens
{
    use ChecksAllowedIps;

    public function __construct(
        protected ResourceServer $server,
        protected ClientRepository $clients,
    ) {
    }

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

        if (trim(Str::after($authHeader, 'Bearer ')) === '') {
            $this->fail('missing-credentials');
        }

        $guard = $this->guard();
        $psr = $this->validateToken($request);

        $client = $this->clients->findActive($this->stringAttribute($psr, 'oauth_client_id'));
        $provider = $guard->getProvider();

        if (! $client instanceof Client || ! $this->belongsToProvider($client, $provider)) {
            $this->fail('token-invalid-or-expired');
        }

        $clientId = $this->clientId($client);

        $oauthUserId = $this->stringAttribute($psr, 'oauth_user_id');
        $actsAsClient = $oauthUserId === '' || ($oauthUserId === $clientId && $client->hasGrantType('client_credentials'));

        $user = $actsAsClient
            ? $this->clientOwner($client)
            : $provider->retrieveById($oauthUserId);

        if (! $actsAsClient && ! $user instanceof Authenticatable) {
            $this->fail('token-invalid-or-expired');
        }

        $grantType = match (true) {
            $actsAsClient => OAuthGrantType::ClientCredentials,
            $client->hasGrantType('personal_access') => OAuthGrantType::PersonalAccess,
            default => OAuthGrantType::AuthorizationCode,
        };

        Context::add(ApiRequestContext::PRINCIPAL_TYPE, ($actsAsClient ? ApiPrincipalType::Client : ApiPrincipalType::User)->value);
        Context::add(ApiRequestContext::OAUTH_CLIENT_ID, $clientId);
        Context::add(ApiRequestContext::OAUTH_TOKEN_ID, $this->stringAttribute($psr, 'oauth_access_token_id'));
        Context::add(ApiRequestContext::OAUTH_SCOPES, $this->scopes($psr));
        Context::add(ApiRequestContext::OAUTH_GRANT_TYPE, $grantType->value);

        if ($user instanceof Authenticatable) {
            Context::add(ApiRequestContext::USER_ID, $user->getAuthIdentifier());
        }

        if (! $this->isIpAllowed($request->ip(), $this->allowedIps($client))) {
            $this->fail('ip-denied');
        }

        if ($user instanceof Authenticatable && ! $this->isEligible($user)) {
            $this->fail('token-invalid-or-expired');
        }

        $guard->setClient($client);

        if ($user instanceof Authenticatable) {
            $guard->setUser($user instanceof OAuthenticatable
                ? $user->withAccessToken(AccessToken::fromPsrRequest($psr))
                : $user);
        }

        Auth::shouldUse($this->guardName());

        $this->authenticated($request, $client, $user);

        return $next($request);
    }

    /**
     * A rate-limit key for a request this middleware has authenticated: the client for
     * client-credentials tokens, the user for every other token, and the IP address for a
     * request it hasn't authenticated. Use it in a limiter that runs after this middleware:
     *
     * ```php
     * RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
     *     ->by(AuthenticatePassportToken::rateLimitKey($request)));
     * ```
     */
    public static function rateLimitKey(Request $request): string
    {
        $clientId = Context::get(ApiRequestContext::OAUTH_CLIENT_ID);

        if (Context::get(ApiRequestContext::PRINCIPAL_TYPE) === ApiPrincipalType::Client->value && is_string($clientId)) {
            return "client:{$clientId}";
        }

        $userId = Context::get(ApiRequestContext::USER_ID);

        if (Context::get(ApiRequestContext::PRINCIPAL_TYPE) === ApiPrincipalType::User->value && (is_int($userId) || is_string($userId))) {
            return "user:{$userId}";
        }

        return 'ip:' . ($request->ip() ?? 'unknown');
    }

    /**
     * The Passport guard to authenticate with, as configured in `auth.guards`.
     */
    protected function guardName(): string
    {
        return 'api';
    }

    /**
     * The client's IP allowlist, or null for none. Supports individual IPs and CIDR notation.
     *
     * Passport has no allowlist column; return one from your own client model or table.
     *
     * @return list<string>|null
     */
    protected function allowedIps(Client $client): ?array
    {
        return null;
    }

    /**
     * Whether this user may use the API at all, checked on every request after the token
     * is validated. Return false for deactivated or otherwise ineligible accounts.
     */
    protected function isEligible(Authenticatable $user): bool
    {
        return true;
    }

    /**
     * The user a client-credentials token acts as, or null to act as the client alone.
     *
     * Defaults to the client's owner when it can sign in, so a service integration can be
     * modelled as a user with roles and policies that own its clients.
     */
    protected function clientOwner(Client $client): ?Authenticatable
    {
        $owner = $client->owner;

        return $owner instanceof Authenticatable ? $owner : null;
    }

    /**
     * Called once the request is authenticated, before it reaches the route. Override to
     * record usage, such as a client's last use. `$user` is null for a client acting for itself.
     */
    protected function authenticated(Request $request, Client $client, ?Authenticatable $user): void
    {
        // Default: no-op.
    }

    /**
     * Fail authentication with a reason.
     *
     * @throws AuthenticationException
     */
    protected function fail(string $reason): never
    {
        Context::add(ApiRequestContext::FAILURE_REASON, $reason);

        throw new AuthenticationException(guards: [$this->guardName()]);
    }

    private function guard(): TokenGuard
    {
        $guard = Auth::guard($this->guardName());

        if (! $guard instanceof TokenGuard) {
            throw new \LogicException("The [{$this->guardName()}] guard must use the passport driver.");
        }

        return $guard;
    }

    /**
     * Validate the bearer token: signature, expiry, and Passport's revocation check.
     */
    private function validateToken(Request $request): ServerRequestInterface
    {
        try {
            return $this->server->validateAuthenticatedRequest((new PsrHttpFactory())->createRequest($request));
        } catch (OAuthServerException) {
            $this->fail('token-invalid-or-expired');
        }
    }

    /**
     * Passport's own check: a client tied to a user provider only issues tokens for that provider.
     */
    private function belongsToProvider(Client $client, mixed $provider): bool
    {
        $clientProvider = $client->getAttribute('provider');

        return ! is_string($clientProvider)
            || $clientProvider === ''
            || ! $provider instanceof PassportUserProvider
            || $clientProvider === $provider->getProviderName();
    }

    private function clientId(Client $client): string
    {
        $key = $client->getKey();

        return is_string($key) || is_int($key) ? (string) $key : '';
    }

    private function stringAttribute(ServerRequestInterface $psr, string $name): string
    {
        $value = $psr->getAttribute($name);

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    private function scopes(ServerRequestInterface $psr): array
    {
        $scopes = $psr->getAttribute('oauth_scopes');

        return is_array($scopes) ? array_values(array_filter($scopes, is_string(...))) : [];
    }
}
