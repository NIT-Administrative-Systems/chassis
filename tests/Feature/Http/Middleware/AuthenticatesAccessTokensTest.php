<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Contracts\AccessTokenContract;
use Northwestern\SysDev\Chassis\Http\Middleware\AuthenticatesAccessTokens;
use Northwestern\SysDev\Chassis\Tests\TestCase;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;

// --- Test doubles ---

class FakeUser implements Authenticatable
{
    public function __construct(public int $id = 42)
    {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return 'password';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}

class FakeAccessToken implements AccessTokenContract
{
    public bool $usageRecorded = false;

    public ?string $usageIp = null;

    /**
     * @param  list<string>|null  $allowedIps
     */
    public function __construct(
        public int $tokenId = 1,
        public int $userId = 42,
        public ?array $allowedIps = null,
        public string $hash = 'stub-hash',
        public bool $active = true,
        public bool $expired = false,
    ) {
    }

    public static function hashFromPlain(#[\SensitiveParameter] string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getTokenHash(): string
    {
        return $this->hash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isExpired(): bool
    {
        return $this->expired;
    }

    public function getAllowedIps(): ?array
    {
        return $this->allowedIps;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTokenId(): int
    {
        return $this->tokenId;
    }

    public function recordUsage(?string $ipAddress): void
    {
        $this->usageRecorded = true;
        $this->usageIp = $ipAddress;
    }

    public function getUser(): ?Authenticatable
    {
        return new FakeUser($this->userId);
    }
}

// --- Concrete middleware for testing ---

class TestAuthenticatesAccessTokens extends AuthenticatesAccessTokens
{
    public static ?FakeAccessToken $token = null;

    protected function findActiveToken(string $tokenHash): ?AccessTokenContract
    {
        return static::$token;
    }

    protected function hashToken(#[\SensitiveParameter] string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}

#[CoversClass(AuthenticatesAccessTokens::class)]
class AuthenticatesAccessTokensTest extends TestCase
{
    protected string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        TestAuthenticatesAccessTokens::$token = null;

        $this->endpoint = '/api/auth-test';

        Route::middleware([TestAuthenticatesAccessTokens::class])
            ->get($this->endpoint, fn () => response()->json(['ok' => true]));

        Auth::partialMock()->shouldReceive('onceUsingId')->andReturn(true)->byDefault();
    }

    protected function tearDown(): void
    {
        Context::flush();

        parent::tearDown();
    }

    public function test_returns_401_when_no_authorization_header(): void
    {
        $this->getJson($this->endpoint)->assertUnauthorized();
    }

    public function test_returns_401_when_authorization_header_does_not_start_with_bearer(): void
    {
        $this->getJson($this->endpoint, ['Authorization' => 'Basic abc123'])
            ->assertUnauthorized();
    }

    public function test_returns_401_when_bearer_token_is_empty(): void
    {
        $this->getJson($this->endpoint, ['Authorization' => 'Bearer '])
            ->assertUnauthorized();
    }

    public function test_returns_401_when_token_hash_does_not_match_any_active_token(): void
    {
        $this->getJson($this->endpoint, ['Authorization' => 'Bearer some-invalid-token'])
            ->assertUnauthorized();
    }

    public function test_returns_401_when_ip_is_denied(): void
    {
        TestAuthenticatesAccessTokens::$token = new FakeAccessToken(allowedIps: ['10.0.0.0/8']);

        $this->getJson($this->endpoint, ['Authorization' => 'Bearer valid-token'])
            ->assertUnauthorized();
    }

    public function test_authenticates_successfully_with_valid_token(): void
    {
        TestAuthenticatesAccessTokens::$token = new FakeAccessToken(tokenId: 5, userId: 42);

        $this->getJson($this->endpoint, ['Authorization' => 'Bearer valid-token'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_sets_trace_id_user_id_and_token_id_in_context(): void
    {
        TestAuthenticatesAccessTokens::$token = new FakeAccessToken(tokenId: 7, userId: 99);

        $capturedContext = [];

        Route::middleware([TestAuthenticatesAccessTokens::class])
            ->get('/api/context-test', function () use (&$capturedContext) {
                $capturedContext = [
                    'trace_id' => Context::get(ApiRequestContext::TRACE_ID),
                    'user_id' => Context::get(ApiRequestContext::USER_ID),
                    'token_id' => Context::get(ApiRequestContext::TOKEN_ID),
                ];

                return response()->json(['ok' => true]);
            });

        $this->getJson('/api/context-test', ['Authorization' => 'Bearer valid-token'])
            ->assertOk();

        $this->assertIsString($capturedContext['trace_id']);
        $this->assertNotEmpty($capturedContext['trace_id']);
        $this->assertSame(99, $capturedContext['user_id']);
        $this->assertSame(7, $capturedContext['token_id']);
    }

    public function test_calls_record_usage_on_success(): void
    {
        $token = new FakeAccessToken();
        TestAuthenticatesAccessTokens::$token = $token;

        $this->getJson($this->endpoint, ['Authorization' => 'Bearer valid-token'])
            ->assertOk();

        $this->assertTrue($token->usageRecorded);
    }

    public function test_ip_restriction_allows_matching_ip(): void
    {
        TestAuthenticatesAccessTokens::$token = new FakeAccessToken(allowedIps: ['127.0.0.0/8']);

        $this->getJson($this->endpoint, ['Authorization' => 'Bearer valid-token'])
            ->assertOk();
    }

    public function test_ip_restriction_denies_non_matching_ip(): void
    {
        TestAuthenticatesAccessTokens::$token = new FakeAccessToken(allowedIps: ['10.0.0.0/8']);

        $this->getJson($this->endpoint, ['Authorization' => 'Bearer valid-token'])
            ->assertUnauthorized();
    }

    public function test_no_ip_restriction_allows_any_ip(): void
    {
        TestAuthenticatesAccessTokens::$token = new FakeAccessToken(allowedIps: null);

        $this->getJson($this->endpoint, ['Authorization' => 'Bearer valid-token'])
            ->assertOk();
    }

    public function test_blank_request_ip_with_ip_restrictions_reports_and_denies(): void
    {
        TestAuthenticatesAccessTokens::$token = new FakeAccessToken(allowedIps: ['192.168.1.100']);

        // Empty REMOTE_ADDR makes $request->ip() return null, which triggers
        // reportMissingIp() and the `blank IP` denial path.
        $this->withServerVariables(['REMOTE_ADDR' => ''])
            ->getJson($this->endpoint, ['Authorization' => 'Bearer valid-token'])
            ->assertUnauthorized();

        $this->assertSame(
            'ip-denied',
            Context::get(ApiRequestContext::FAILURE_REASON),
        );
    }

    public function test_default_report_missing_ip_is_a_noop(): void
    {
        // Subclass without an overridden reportMissingIp() to exercise the
        // default (no-op) implementation.
        $middleware = new class extends AuthenticatesAccessTokens
        {
            protected function findActiveToken(string $tokenHash): AccessTokenContract
            {
                return new FakeAccessToken(allowedIps: ['192.168.1.100']);
            }

            protected function hashToken(#[\SensitiveParameter] string $plainToken): string
            {
                return hash('sha256', $plainToken);
            }
        };

        $reflection = new \ReflectionClass(AuthenticatesAccessTokens::class);
        $method = $reflection->getMethod('reportMissingIp');
        $method->setAccessible(true);

        // Directly invoke the default reportMissingIp() — the base implementation
        // is a no-op, so we just assert it returns without throwing.
        $method->invoke($middleware, ['192.168.1.100']);

        $this->assertTrue(true);
    }
}
