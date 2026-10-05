<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware\Concerns;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * IP allowlist checks shared by the bearer token middleware.
 */
trait ChecksAllowedIps
{
    /**
     * Check if the request IP is allowed by an IP allowlist.
     *
     * Supports individual IPs and CIDR notation. An empty or null allowlist
     * allows every IP. Override to customize behavior when the request IP is
     * missing (e.g. behind a proxy).
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
     * Called when a credential has IP restrictions but the request IP is missing.
     *
     * Override to report this as an exception or log it.
     *
     * @param  list<string>  $allowedIps
     */
    protected function reportMissingIp(array $allowedIps): void
    {
        // Default: no-op. Override to report.
    }
}
