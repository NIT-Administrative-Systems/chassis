<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Rules;

use Illuminate\Support\Facades\Validator;
use Northwestern\SysDev\Chassis\Rules\OAuthRedirectUri;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(OAuthRedirectUri::class)]
class OAuthRedirectUriTest extends TestCase
{
    #[DataProvider('uris')]
    public function test_it_accepts_https_and_loopback_only(mixed $uri, bool $valid): void
    {
        $this->assertSame($valid, Validator::make(['uri' => $uri], ['uri' => [new OAuthRedirectUri()]])->passes());
    }

    // Desktop clients, such as MCP clients in an editor, return through their own scheme when it's allowed.
    public function test_it_accepts_only_the_custom_schemes_it_is_given(): void
    {
        $rule = new OAuthRedirectUri(['cursor']);

        $this->assertTrue(Validator::make(['uri' => 'cursor://anysphere.cursor-mcp/oauth/callback'], ['uri' => [$rule]])->passes());
        $this->assertFalse(Validator::make(['uri' => 'vscode://vscode.github-authentication/did-authenticate'], ['uri' => [$rule]])->passes());
        $this->assertFalse(Validator::make(['uri' => 'cursor://anysphere%2Fcursor-mcp/oauth/callback'], ['uri' => [$rule]])->passes());
    }

    public function test_the_message_explains_what_is_wrong(): void
    {
        $rule = new OAuthRedirectUri();

        $this->assertSame(
            'Each redirect URI must be an absolute URL without a fragment.',
            Validator::make(['uri' => '/callback'], ['uri' => [$rule]])->errors()->first('uri'),
        );
        $this->assertSame(
            'Each redirect URI must use HTTPS, or HTTP to localhost.',
            Validator::make(['uri' => 'http://app.example.edu/callback'], ['uri' => [$rule]])->errors()->first('uri'),
        );
        $this->assertSame(
            'Each redirect URI must have a valid host name.',
            Validator::make(['uri' => 'https://app.example.edu%40evil.example/callback'], ['uri' => [$rule]])->errors()->first('uri'),
        );
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function uris(): array
    {
        return [
            'https' => ['https://app.example.edu/callback', true],
            'loopback http' => ['http://localhost:4100/callback', true],
            'loopback ip' => ['http://127.0.0.1/callback', true],
            'loopback ipv6' => ['http://[::1]:4100/callback', true],
            'plain http elsewhere' => ['http://app.example.edu/callback', false],
            'fragment' => ['https://app.example.edu/callback#x', false],
            'relative' => ['/callback', false],
            'custom scheme' => ['myapp://callback', false],
            'credentials' => ['https://user:secret@app.example.edu/callback', false],
            // A consent screen shows the host, so it must be one that can't pose as another site.
            'internationalized domain' => ['https://bücher.example/callback', true],
            'right-to-left override' => ["https://\u{202E}moc.example/callback", false],
            'encoded at sign' => ['https://app.example.edu%40evil.example/callback', false],
            'invalid punycode' => ['https://xn--zz.example/callback', false],
            'not a string' => [['https://app.example.edu/callback'], false],
        ];
    }
}
