<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Http\Middleware;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Middleware\CheckToken;
use Northwestern\SysDev\Chassis\Http\Middleware\AuthenticatesPassportTokens;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Passport\PassportUser;
use Northwestern\SysDev\Chassis\Tests\PassportTestCase;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;

class TestAuthenticatePassportToken extends AuthenticatesPassportTokens
{
    /** @var list<string>|null */
    public static ?array $allowedIps = null;

    protected function allowedIps(Client $client): ?array
    {
        return self::$allowedIps;
    }

    protected function isEligible(Authenticatable $user): bool
    {
        return $user instanceof PassportUser && $user->active;
    }

    /** @var list<array{client: mixed, user: mixed}> */
    public static array $authenticated = [];

    protected function authenticated(Request $request, Client $client, ?Authenticatable $user): void
    {
        self::$authenticated[] = ['client' => $client->getKey(), 'user' => $user?->getAuthIdentifier()];
    }
}

#[CoversClass(AuthenticatesPassportTokens::class)]
#[CoversTrait(\Northwestern\SysDev\Chassis\Http\Middleware\Concerns\ChecksAllowedIps::class)]
final class AuthenticatesPassportTokensTest extends PassportTestCase
{
    /** @var array<string, mixed> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        TestAuthenticatePassportToken::$allowedIps = null;
        TestAuthenticatePassportToken::$authenticated = [];

        Route::middleware(TestAuthenticatePassportToken::class)->get('/api/whoami', function (Request $request) {
            $this->seen = [
                'user' => $request->user()?->getAuthIdentifier(),
                'client' => Auth::guard('api')->client()?->getKey(),
                'can_view' => $request->user()?->tokenCan('users:view'),
                'rate_limit_key' => TestAuthenticatePassportToken::rateLimitKey($request),
            ];

            return response()->json(['ok' => true]);
        });

        Route::middleware([TestAuthenticatePassportToken::class, CheckToken::using('users:edit')])
            ->get('/api/edit', fn () => response()->json(['ok' => true]));
    }

    public function test_a_personal_access_token_authenticates_its_user(): void
    {
        $user = $this->createUser();
        $client = $this->clients()->createPersonalAccessGrantClient('Personal', 'users');
        $result = $user->createToken('Script', ['users:view']);

        $this->withToken($result->accessToken)->getJson('/api/whoami')->assertOk();

        $this->assertSame($user->getKey(), $this->seen['user']);
        $this->assertSame($client->getKey(), $this->seen['client']);
        $this->assertTrue($this->seen['can_view']);
        $this->assertSame("user:{$user->getKey()}", $this->seen['rate_limit_key']);

        $this->assertSame('user', Context::get(ApiRequestContext::PRINCIPAL_TYPE));
        $this->assertSame($user->getKey(), Context::get(ApiRequestContext::USER_ID));
        $this->assertSame((string) $client->getKey(), Context::get(ApiRequestContext::OAUTH_CLIENT_ID));
        $this->assertSame($result->token->getKey(), Context::get(ApiRequestContext::OAUTH_TOKEN_ID));
        $this->assertSame(['users:view'], Context::get(ApiRequestContext::OAUTH_SCOPES));
        $this->assertSame('personal_access', Context::get(ApiRequestContext::OAUTH_GRANT_TYPE));
        $this->assertNull(Context::get(ApiRequestContext::FAILURE_REASON));
        $this->assertNull(Context::get(ApiRequestContext::TOKEN_ID));
        $this->assertIsString(Context::get(ApiRequestContext::TRACE_ID));
    }

    public function test_an_authorization_code_token_is_recorded_as_one(): void
    {
        $user = $this->createUser();
        $client = $this->clients()->createAuthorizationCodeGrantClient('Reporting app', ['https://app.example.test/callback']);

        $this->withToken($this->issueToken($client, $user, ['users:view']))->getJson('/api/whoami')->assertOk();

        $this->assertSame($user->getKey(), $this->seen['user']);
        $this->assertSame('user', Context::get(ApiRequestContext::PRINCIPAL_TYPE));
        $this->assertSame('authorization_code', Context::get(ApiRequestContext::OAUTH_GRANT_TYPE));
    }

    // Exercises the real client credentials grant, then the owner rule.
    public function test_client_credentials_act_as_the_clients_owner(): void
    {
        $owner = $this->createUser();
        $client = $this->clients()->createClientCredentialsGrantClient('Nightly sync');
        $client->owner()->associate($owner)->save();

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'scope' => 'users:view',
        ])->assertOk()->json('access_token');

        $this->withToken($token)->getJson('/api/whoami')->assertOk();

        $this->assertSame($owner->getKey(), $this->seen['user']);
        $this->assertSame($client->getKey(), $this->seen['client']);
        $this->assertTrue($this->seen['can_view']);
        $this->assertSame("client:{$client->getKey()}", $this->seen['rate_limit_key']);

        $this->assertSame('client', Context::get(ApiRequestContext::PRINCIPAL_TYPE));
        $this->assertSame($owner->getKey(), Context::get(ApiRequestContext::USER_ID));
        $this->assertSame('client_credentials', Context::get(ApiRequestContext::OAUTH_GRANT_TYPE));
    }

    public function test_client_credentials_without_an_owner_act_as_the_client_alone(): void
    {
        $client = $this->clients()->createClientCredentialsGrantClient('Nightly sync');

        $this->withToken($this->issueToken($client, null, ['users:view']))->getJson('/api/whoami')->assertOk();

        $this->assertNull($this->seen['user']);
        $this->assertSame($client->getKey(), $this->seen['client']);
        $this->assertSame('client', Context::get(ApiRequestContext::PRINCIPAL_TYPE));
        $this->assertNull(Context::get(ApiRequestContext::USER_ID));
    }

    public function test_passport_scope_middleware_sees_the_token(): void
    {
        $user = $this->createUser();
        $client = $this->clients()->createAuthorizationCodeGrantClient('Reporting app', ['https://app.example.test/callback']);

        $this->withToken($this->issueToken($client, $user, ['users:view']))->getJson('/api/edit')->assertForbidden();
        $this->withToken($this->issueToken($client, $user, ['users:edit']))->getJson('/api/edit')->assertOk();
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'no header' => [[], 'invalid-header-format'];
        yield 'another scheme' => [['Authorization' => 'Basic abc'], 'invalid-header-format'];
        yield 'empty bearer' => [['Authorization' => 'Bearer   '], 'missing-credentials'];
        yield 'not a token' => [['Authorization' => 'Bearer not-a-jwt'], 'token-invalid-or-expired'];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaders')]
    public function test_it_records_why_a_request_was_refused(array $headers, string $reason): void
    {
        $this->getJson('/api/whoami', $headers)->assertUnauthorized();

        $this->assertSame($reason, Context::get(ApiRequestContext::FAILURE_REASON));
        $this->assertNull(Context::get(ApiRequestContext::PRINCIPAL_TYPE));
        $this->assertIsString(Context::get(ApiRequestContext::TRACE_ID));
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $user = $this->createUser();
        $this->clients()->createPersonalAccessGrantClient('Personal', 'users');
        $result = $user->createToken('Script');
        $result->token->revoke();

        $this->withToken($result->accessToken)->getJson('/api/whoami')->assertUnauthorized();

        $this->assertSame('token-invalid-or-expired', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $client = $this->clients()->createAuthorizationCodeGrantClient('Reporting app', ['https://app.example.test/callback']);

        $this->withToken($this->issueToken($client, $this->createUser(), expiresIn: '-1 minute'))->getJson('/api/whoami')->assertUnauthorized();

        $this->assertSame('token-invalid-or-expired', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_a_token_from_a_revoked_client_is_refused(): void
    {
        $client = $this->clients()->createAuthorizationCodeGrantClient('Reporting app', ['https://app.example.test/callback']);
        $token = $this->issueToken($client, $this->createUser());
        $client->forceFill(['revoked' => true])->save();

        $this->withToken($token)->getJson('/api/whoami')->assertUnauthorized();

        $this->assertSame('token-invalid-or-expired', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_a_token_for_a_deleted_user_is_refused(): void
    {
        $user = $this->createUser();
        $client = $this->clients()->createAuthorizationCodeGrantClient('Reporting app', ['https://app.example.test/callback']);
        $token = $this->issueToken($client, $user);
        $user->delete();

        $this->withToken($token)->getJson('/api/whoami')->assertUnauthorized();

        $this->assertSame('token-invalid-or-expired', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_a_client_tied_to_another_user_provider_is_refused(): void
    {
        $client = $this->clients()->createPersonalAccessGrantClient('Admins only', 'admins');

        $this->withToken($this->issueToken($client, $this->createUser()))->getJson('/api/whoami')->assertUnauthorized();

        $this->assertSame('token-invalid-or-expired', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_an_ineligible_user_is_refused(): void
    {
        $client = $this->clients()->createAuthorizationCodeGrantClient('Reporting app', ['https://app.example.test/callback']);

        $this->withToken($this->issueToken($client, $this->createUser(active: false)))->getJson('/api/whoami')->assertUnauthorized();

        $this->assertSame('token-invalid-or-expired', Context::get(ApiRequestContext::FAILURE_REASON));
    }

    public function test_the_client_ip_allowlist_applies(): void
    {
        $client = $this->clients()->createClientCredentialsGrantClient('Nightly sync');
        $token = $this->issueToken($client, null);

        TestAuthenticatePassportToken::$allowedIps = ['10.0.0.0/8'];
        $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->getJson('/api/whoami')->assertUnauthorized();
        $this->assertSame('ip-denied', Context::get(ApiRequestContext::FAILURE_REASON));

        Context::flush();
        $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->getJson('/api/whoami')->assertOk();
    }

    public function test_it_reports_each_authenticated_request_and_no_refused_one(): void
    {
        $client = $this->clients()->createClientCredentialsGrantClient('Nightly sync');

        $this->withToken($this->issueToken($client, null))->getJson('/api/whoami')->assertOk();
        $this->withToken('not-a-jwt')->getJson('/api/whoami')->assertUnauthorized();

        $this->assertSame([['client' => $client->getKey(), 'user' => null]], TestAuthenticatePassportToken::$authenticated);
    }

    public function test_requests_it_has_not_authenticated_are_rate_limited_by_ip(): void
    {
        $request = Request::create('/api/whoami', server: ['REMOTE_ADDR' => '192.0.2.1']);

        $this->assertSame('ip:192.0.2.1', TestAuthenticatePassportToken::rateLimitKey($request));
    }

    public function test_the_guard_must_use_the_passport_driver(): void
    {
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        Auth::forgetGuards();

        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);

        $this->withToken('anything')->getJson('/api/whoami');
    }
}
