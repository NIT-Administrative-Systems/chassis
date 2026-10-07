<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use Northwestern\SysDev\Chassis\ValueObjects\OAuthRedirectTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthRedirectTarget::class)]
class OAuthRedirectTargetTest extends TestCase
{
    #[DataProvider('targets')]
    public function test_it_shows_the_scheme_host_and_port(string $uri, string $display, ?string $punycode, bool $isLoopback): void
    {
        $target = OAuthRedirectTarget::from($uri);

        $this->assertSame($display, $target->display);
        $this->assertSame($punycode, $target->punycode);
        $this->assertSame($isLoopback, $target->isLoopback);
    }

    #[DataProvider('invalidUris')]
    public function test_it_refuses_a_host_it_cannot_show(string $uri): void
    {
        $this->assertNull(OAuthRedirectTarget::tryFrom($uri));
    }

    public function test_from_throws_for_a_host_it_cannot_show(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OAuthRedirectTarget::from('/callback');
    }

    /**
     * @return array<string, array{string, string, ?string, bool}>
     */
    public static function targets(): array
    {
        return [
            'https' => ['https://app.example.edu/oauth/callback?x=1', 'https://app.example.edu', null, false],
            'port' => ['https://app.example.edu:8443/callback', 'https://app.example.edu:8443', null, false],
            'case' => ['HTTPS://App.Example.EDU/callback', 'https://app.example.edu', null, false],
            'localhost' => ['http://localhost:4100/callback', 'http://localhost:4100', null, true],
            'loopback ip' => ['http://127.0.0.1:33418/callback', 'http://127.0.0.1:33418', null, true],
            'loopback ipv6' => ['http://[::1]:33418/callback', 'http://[::1]:33418', null, true],
            'custom scheme' => ['vscode://vscode.github-authentication/did-authenticate', 'vscode://vscode.github-authentication', null, false],
            'unicode domain' => ['https://bücher.example/callback', 'https://bücher.example', 'https://xn--bcher-kva.example', false],
            'punycode domain' => ['https://xn--bcher-kva.example/callback', 'https://bücher.example', 'https://xn--bcher-kva.example', false],
            'percent-encoded domain' => ['https://b%C3%BCcher.example/callback', 'https://bücher.example', 'https://xn--bcher-kva.example', false],
            'look-alike letters' => ["https://\u{0430}pple.com/callback", "https://\u{0430}pple.com", 'https://xn--pple-43d.com', false],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUris(): array
    {
        return [
            'relative' => ['/callback'],
            'no host' => ['myapp:/callback'],
            'right-to-left override' => ["https://\u{202E}moc.example/callback"],
            'invalid punycode' => ['https://xn--zz.example/callback'],
            'empty label' => ['https://app..example/callback'],
            'encoded slash' => ['https://evil.example%2Fapp.example.edu/callback'],
            'encoded at sign' => ['https://app.example.edu%40evil.example/callback'],
            'space' => ['https://app example.edu/callback'],
            'bad ipv6' => ['http://[::g]/callback'],
        ];
    }
}
