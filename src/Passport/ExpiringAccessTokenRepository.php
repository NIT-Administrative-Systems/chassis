<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Passport;

use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;

/**
 * Passport's access token repository, with per-token expiry.
 *
 * Passport sets one lifetime for all personal access tokens. Bind this repository and an
 * access token is also rejected once its `oauth_access_tokens.expires_at` has passed, so
 * an application can give a token a shorter life after creating it:
 *
 * ```php
 * // AppServiceProvider::register()
 * $this->app->bind(AccessTokenRepository::class, ExpiringAccessTokenRepository::class);
 *
 * // When issuing
 * $result = $user->createToken('Nightly export', ['users:view']);
 * $result->token->forceFill(['expires_at' => now()->addDays(30)])->save();
 * ```
 *
 * The check runs inside Passport's existing revocation query, so it adds no query.
 * The token's own `exp` claim still applies; a database expiry can only shorten it.
 */
class ExpiringAccessTokenRepository extends AccessTokenRepository
{
    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return Passport::token()->newQuery()
            ->whereKey($tokenId)
            ->where('revoked', false)
            ->where(fn (\Illuminate\Contracts\Database\Query\Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->doesntExist();
    }
}
