<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for access token models used by the AuthenticatesAccessTokens middleware.
 *
 * Implement this on your application's token model (e.g. AccessToken, ApiToken)
 * to enable the chassis's bearer token authentication middleware.
 */
interface AccessTokenContract
{
    /**
     * Generate a hash for the given plain token.
     *
     * @param  non-empty-string  $plainToken  The plain token to hash.
     * @return non-empty-string The resulting hash.
     */
    public static function hashFromPlain(#[\SensitiveParameter] string $plainToken): string;

    /**
     * Get the token's hash value.
     */
    public function getTokenHash(): string;

    /**
     * Whether this token is active (not revoked, user is valid, etc.).
     */
    public function isActive(): bool;

    /**
     * Whether this token has expired.
     */
    public function isExpired(): bool;

    /**
     * Get the list of allowed IP addresses/CIDR ranges, or null if unrestricted.
     *
     * @return list<string>|null
     */
    public function getAllowedIps(): ?array;

    /**
     * Get the ID of the user who owns this token.
     */
    public function getUserId(): int;

    /**
     * Get the token's primary key.
     */
    public function getTokenId(): int;

    /**
     * Record that the token was used from the given IP address.
     */
    public function recordUsage(?string $ipAddress): void;

    /**
     * Get the authenticated user for this token.
     */
    public function getUser(): ?Authenticatable;
}
