<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Passport;

use Illuminate\Support\Facades\Route;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use Northwestern\SysDev\Chassis\Http\Middleware\AuthenticatesPassportTokens;
use Northwestern\SysDev\Chassis\Passport\ExpiringAccessTokenRepository;
use Northwestern\SysDev\Chassis\Tests\PassportTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

class ExpiryTestAuthenticatePassportToken extends AuthenticatesPassportTokens
{
}

#[CoversClass(ExpiringAccessTokenRepository::class)]
final class ExpiringAccessTokenRepositoryTest extends PassportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(AccessTokenRepository::class, ExpiringAccessTokenRepository::class);

        Route::middleware(ExpiryTestAuthenticatePassportToken::class)
            ->get('/api/things', fn () => response()->json(['ok' => true]));

        $this->clients()->createPersonalAccessGrantClient('Personal', 'users');
    }

    public function test_a_token_past_its_database_expiry_is_refused(): void
    {
        $result = $this->createUser()->createToken('Script');

        $this->withToken($result->accessToken)->getJson('/api/things')->assertOk();

        $result->token->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($result->accessToken)->getJson('/api/things')->assertUnauthorized();
    }

    public function test_a_shorter_database_expiry_still_in_the_future_is_accepted(): void
    {
        $result = $this->createUser()->createToken('Script');
        $result->token->forceFill(['expires_at' => now()->addDays(30)])->save();

        $this->withToken($result->accessToken)->getJson('/api/things')->assertOk();
    }

    public function test_revoked_and_missing_tokens_are_still_revoked(): void
    {
        $result = $this->createUser()->createToken('Script');
        $repository = resolve(AccessTokenRepository::class);

        $this->assertFalse($repository->isAccessTokenRevoked($result->token->getKey()));

        Passport::token()->newQuery()->whereKey($result->token->getKey())->update(['revoked' => true]);

        $this->assertTrue($repository->isAccessTokenRevoked($result->token->getKey()));
        $this->assertTrue($repository->isAccessTokenRevoked('no-such-token'));
    }
}
