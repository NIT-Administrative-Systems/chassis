<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Northwestern\SysDev\Chassis\ValueObjects\OAuthRedirectTarget;

/**
 * An OAuth application's redirect URI: an absolute HTTPS URL, or plain HTTP to the loopback
 * address for applications running on the user's own computer. No fragment, as OAuth 2.0
 * requires, and no credentials. A desktop client may also return through one of
 * `$customSchemes`, its own URI scheme (RFC 8252). The host must be a valid domain name or IP
 * address, so a consent screen can show it with {@see OAuthRedirectTarget}: a host with a
 * right-to-left override, or one that decodes to `/` or `@`, could read as a different site.
 *
 * ```php
 * 'redirect_uris.*' => ['required', new OAuthRedirectUri(['cursor', 'vscode'])],
 * ```
 */
class OAuthRedirectUri implements ValidationRule
{
    /**
     * @param  list<string>  $customSchemes  Lowercase URI schemes accepted in addition to HTTPS
     */
    public function __construct(
        private readonly array $customSchemes = [],
    ) {
        //
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            $fail('Each redirect URI must be an absolute URL without a fragment.');

            return;
        }

        if (! OAuthRedirectTarget::tryFrom($value) instanceof OAuthRedirectTarget) {
            $fail('Each redirect URI must have a valid host name.');

            return;
        }

        if (in_array(strtolower($parts['scheme']), $this->customSchemes, true)) {
            return;
        }

        $loopback = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);

        if ($parts['scheme'] !== 'https' && ($parts['scheme'] !== 'http' || ! $loopback)) {
            $fail('Each redirect URI must use HTTPS, or HTTP to localhost.');
        }
    }
}
