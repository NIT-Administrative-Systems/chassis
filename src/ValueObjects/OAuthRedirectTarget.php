<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\ValueObjects;

use InvalidArgumentException;

/**
 * Where an OAuth redirect URI sends the person, for a consent screen to show before they
 * approve: the scheme, host and port, without the path and query.
 *
 * A client names itself, so the destination is the part a person can check. An
 * internationalized domain can imitate another site's name (`аpple.com` with a Cyrillic а),
 * so its punycode form (`xn--pple-43d.com`) is given too, for the screen to show beside it.
 * A loopback destination is an application on the person's own computer, and any program
 * there can listen on it.
 *
 * ```php
 * $target = OAuthRedirectTarget::from($request->query('redirect_uri'));
 *
 * $target->display;     // https://bücher.example
 * $target->punycode;    // https://xn--bcher-kva.example, or null for a plain ASCII domain
 * $target->isLoopback;  // true for localhost, 127.0.0.1 and [::1]
 * ```
 *
 * Pass a URI that passed the `OAuthRedirectUri` rule, which refuses any host this can't show.
 */
final readonly class OAuthRedirectTarget
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Characters a browser refuses in a host name, once percent-decoded (the WHATWG URL
     * standard's forbidden domain code points). A decoded `/` or `@` could otherwise make the
     * host read as a different address.
     */
    private const string FORBIDDEN_HOST_CHARACTERS = '/[\x00-\x20\x7F#%\/:<>?@\[\\\\\]^|]/';

    private function __construct(
        public string $display,
        public ?string $punycode,
        public bool $isLoopback,
    ) {
        //
    }

    /**
     * @throws InvalidArgumentException When the URI has no scheme or host, or the host isn't a valid domain name or IP address
     */
    public static function from(string $redirectUri): self
    {
        return self::tryFrom($redirectUri)
            ?? throw new InvalidArgumentException('The redirect URI has no host that can be shown.');
    }

    public static function tryFrom(string $redirectUri): ?self
    {
        $parts = parse_url($redirectUri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $hosts = self::hosts($parts['host']);

        if ($hosts === null) {
            return null;
        }

        [$ascii, $unicode] = $hosts;
        $prefix = strtolower($parts['scheme']) . '://';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return new self(
            display: $prefix . $unicode . $port,
            punycode: $ascii === $unicode ? null : $prefix . $ascii . $port,
            isLoopback: in_array($ascii, self::LOOPBACK_HOSTS, true),
        );
    }

    /**
     * The host's ASCII (punycode) and Unicode forms, or null when it isn't a valid host.
     *
     * @return array{string, string}|null
     */
    private static function hosts(string $host): ?array
    {
        if (str_starts_with($host, '[')) {
            $address = filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

            return str_ends_with($host, ']') && $address !== false ? [strtolower($host), strtolower($host)] : null;
        }

        $decoded = rawurldecode($host);

        if ($decoded === '' || preg_match(self::FORBIDDEN_HOST_CHARACTERS, $decoded) === 1) {
            return null;
        }

        $ascii = idn_to_ascii($decoded, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        $unicode = $ascii === false ? false : idn_to_utf8($ascii, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false || $unicode === false ? null : [$ascii, $unicode];
    }
}
