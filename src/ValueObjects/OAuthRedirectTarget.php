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
 * It refuses, rather than guesses at, a URI that PHP's `parse_url()` and a browser would read
 * differently: whitespace, a backslash, credentials, a malformed port, or a host a browser
 * rewrites (`0x7f.1` is `127.0.0.1`), so what it shows is where the browser goes. The
 * `OAuthRedirectUri` rule refuses the same URIs at registration.
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

    /**
     * Characters PHP's `parse_url()` and a browser read differently anywhere in a URI: a browser
     * drops tabs and newlines and reads a backslash as `/`, so `https://evil.example\@app.example.edu`
     * goes to `evil.example` while `parse_url()` finds `app.example.edu`.
     */
    private const string AMBIGUOUS_URI_CHARACTERS = '/[\x00-\x20\x7F\\\\]/';

    /**
     * The authority a browser accepts once credentials are refused: a host and an optional port of
     * digits. `parse_url()` reads `a:0x` as port 0, where a browser refuses the URL.
     */
    private const string AUTHORITY = '#^[a-z][a-z0-9+.\-]*://(\[[^\]/?\#]*\]|[^:\[\]/?\#]*)(:\d*)?(?:[/?\#]|$)#i';

    /**
     * A last label a browser reads as a number, turning the host into an IPv4 address: `0x7f.1`
     * goes to `127.0.0.1`.
     */
    private const string NUMERIC_LABEL = '/^(\d+|0x[0-9a-f]*)$/i';

    private const int IDNA_OPTIONS = IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    private function __construct(
        public string $display,
        public ?string $punycode,
        public bool $isLoopback,
    ) {
        //
    }

    /**
     * @throws InvalidArgumentException When the URI has no scheme or host, has credentials, whitespace or a backslash, or the host isn't a valid domain name or IP address
     */
    public static function from(string $redirectUri): self
    {
        return self::tryFrom($redirectUri)
            ?? throw new InvalidArgumentException('The redirect URI has no host that can be shown.');
    }

    public static function tryFrom(string $redirectUri): ?self
    {
        if (preg_match(self::AMBIGUOUS_URI_CHARACTERS, $redirectUri) === 1 || preg_match(self::AUTHORITY, $redirectUri) !== 1) {
            return null;
        }

        $parts = parse_url($redirectUri);

        // Credentials put an `@` before the host, where a browser and `parse_url()` can split differently.
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
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
            // The address's shortest form, so `[0:0::1]` shows, and counts as loopback, as `[::1]`.
            $packed = is_string($address) && str_ends_with($host, ']') ? inet_pton($address) : false;
            $shortest = $packed === false ? false : inet_ntop($packed);

            return $shortest === false ? null : ['[' . $shortest . ']', '[' . $shortest . ']'];
        }

        $decoded = rawurldecode($host);

        if ($decoded === '' || preg_match(self::FORBIDDEN_HOST_CHARACTERS, $decoded) === 1) {
            return null;
        }

        $ascii = idn_to_ascii($decoded, IDNA_NONTRANSITIONAL_TO_ASCII | self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);
        $unicode = $ascii === false ? false : idn_to_utf8($ascii, IDNA_NONTRANSITIONAL_TO_UNICODE | self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || $unicode === false) {
            return null;
        }

        // A host ending in a number is an IPv4 address to a browser; show only the dotted form it would use.
        $labels = explode('.', rtrim($ascii, '.'));

        if (preg_match(self::NUMERIC_LABEL, end($labels)) === 1 && filter_var($ascii, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return [$ascii, $unicode];
    }
}
