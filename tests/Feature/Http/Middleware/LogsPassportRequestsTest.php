<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Support\Facades\Route;
use Northwestern\SysDev\Chassis\Http\Middleware\AuthenticatesPassportTokens;
use Northwestern\SysDev\Chassis\Http\Middleware\LogsPassportRequests;
use Northwestern\SysDev\Chassis\Tests\PassportTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

class LoggingTestAuthenticatePassportToken extends AuthenticatesPassportTokens
{
}

class TestLogsPassportRequests extends LogsPassportRequests
{
    /** @var list<array<string, mixed>> */
    public static array $logs = [];

    protected function isEnabled(): bool
    {
        return true;
    }

    protected function isSamplingEnabled(): bool
    {
        return false;
    }

    protected function sampleRate(): float
    {
        return 1.0;
    }

    protected function persistLog(array $data): void
    {
        static::$logs[] = $data;
    }
}

#[CoversClass(LogsPassportRequests::class)]
final class LogsPassportRequestsTest extends PassportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestLogsPassportRequests::$logs = [];

        Route::middleware([TestLogsPassportRequests::class, LoggingTestAuthenticatePassportToken::class])
            ->get('/api/things', fn () => response()->json(['ok' => true]));

        Route::middleware(TestLogsPassportRequests::class)
            ->get('/api/open', fn () => response()->json(['ok' => true]));
    }

    // LogsApiRequests skips a request with no user and no failure, which is every
    // successful client-credentials request from a client without an owner.
    public function test_a_client_acting_for_itself_is_logged(): void
    {
        $client = $this->clients()->createClientCredentialsGrantClient('Nightly sync');

        $this->withToken($this->issueToken($client, null, ['users:view']))->getJson('/api/things')->assertOk();

        $this->assertCount(1, TestLogsPassportRequests::$logs);
        $log = TestLogsPassportRequests::$logs[0];

        $this->assertNull($log['user_id']);
        $this->assertNull($log['token_id']);
        $this->assertSame('client', $log['principal_type']);
        $this->assertSame((string) $client->getKey(), $log['oauth_client_id']);
        $this->assertIsString($log['oauth_token_id']);
        $this->assertSame('client_credentials', $log['oauth_grant_type']);
        $this->assertSame(['users:view'], $log['oauth_scopes']);
        $this->assertSame(200, $log['status_code']);
        $this->assertIsString($log['trace_id']);
    }

    public function test_a_person_is_logged_with_their_user_id(): void
    {
        $user = $this->createUser();
        $client = $this->clients()->createAuthorizationCodeGrantClient('Reporting app', ['https://app.example.test/callback']);

        $this->withToken($this->issueToken($client, $user))->getJson('/api/things')->assertOk();

        $log = TestLogsPassportRequests::$logs[0];
        $this->assertSame($user->getKey(), $log['user_id']);
        $this->assertSame('user', $log['principal_type']);
        $this->assertSame('authorization_code', $log['oauth_grant_type']);
    }

    public function test_a_refused_request_is_logged_with_its_reason(): void
    {
        $this->withToken('not-a-jwt')->getJson('/api/things')->assertUnauthorized();

        $log = TestLogsPassportRequests::$logs[0];
        $this->assertSame('token-invalid-or-expired', $log['failure_reason']);
        $this->assertNull($log['principal_type']);
        $this->assertSame(401, $log['status_code']);
    }

    public function test_a_request_nobody_authenticated_is_not_logged(): void
    {
        $this->getJson('/api/open')->assertOk();

        $this->assertSame([], TestLogsPassportRequests::$logs);
    }
}
