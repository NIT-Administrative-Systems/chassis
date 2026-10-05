<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Feature\Passport;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Northwestern\SysDev\Chassis\Passport\AccessRevoker;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Passport\PassportUser;
use Northwestern\SysDev\Chassis\Tests\PassportTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AccessRevoker::class)]
final class AccessRevokerTest extends PassportTestCase
{
    public function test_it_disconnects_a_user_from_one_client(): void
    {
        $user = $this->createUser();
        $reporting = $this->authorizationCodeClient('Reporting app');
        $calendar = $this->authorizationCodeClient('Calendar app');

        $reportingToken = $this->grant($user, $reporting);
        $calendarToken = $this->grant($user, $calendar);
        $reportingCode = $this->authCode($user, $reporting);

        $this->assertSame(1, resolve(AccessRevoker::class)->revokeClient($user, $reporting));

        $this->assertTrue($this->tokenRevoked($reportingToken));
        $this->assertTrue($this->refreshTokenRevoked($reportingToken));
        $this->assertTrue($this->authCodeRevoked($reportingCode));

        $this->assertFalse($this->tokenRevoked($calendarToken));
        $this->assertFalse($this->refreshTokenRevoked($calendarToken));
    }

    // Revoking an access token leaves its refresh token valid, so an earlier revoke of the
    // access token alone must not hide the refresh token from a later disconnect.
    public function test_it_revokes_refresh_tokens_of_access_tokens_already_revoked(): void
    {
        $user = $this->createUser();
        $client = $this->authorizationCodeClient('Reporting app');
        $tokenId = $this->grant($user, $client);
        Passport::token()->newQuery()->whereKey($tokenId)->update(['revoked' => true]);

        resolve(AccessRevoker::class)->revokeClient($user, (string) $client->getKey());

        $this->assertTrue($this->refreshTokenRevoked($tokenId));
    }

    public function test_it_leaves_other_users_of_a_shared_client_connected(): void
    {
        $willie = $this->createUser();
        $other = $this->createUser();
        $client = $this->authorizationCodeClient('Reporting app');

        $this->grant($willie, $client);
        $othersToken = $this->grant($other, $client);

        resolve(AccessRevoker::class)->revokeClient($willie, $client);

        $this->assertFalse($this->tokenRevoked($othersToken));
        $this->assertFalse($this->refreshTokenRevoked($othersToken));
        $this->assertFalse(Client::query()->findOrFail($client->getKey())->revoked);
    }

    public function test_it_disconnects_a_user_from_every_client(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $reporting = $this->authorizationCodeClient('Reporting app');
        $calendar = $this->authorizationCodeClient('Calendar app');

        $tokens = [$this->grant($user, $reporting), $this->grant($user, $calendar)];
        $othersToken = $this->grant($other, $reporting);

        $this->assertSame(2, resolve(AccessRevoker::class)->revokeAll($user));

        foreach ($tokens as $tokenId) {
            $this->assertTrue($this->tokenRevoked($tokenId));
            $this->assertTrue($this->refreshTokenRevoked($tokenId));
        }

        $this->assertFalse($this->tokenRevoked($othersToken));
    }

    private function authorizationCodeClient(string $name): Client
    {
        return $this->clients()->createAuthorizationCodeGrantClient($name, ['https://app.example.test/callback']);
    }

    /**
     * An access token and the refresh token issued with it. Returns the access token's ID.
     */
    private function grant(PassportUser $user, Client $client): string
    {
        $tokenId = Str::random(80);

        Passport::token()->forceFill([
            'id' => $tokenId,
            'user_id' => $user->getKey(),
            'client_id' => $client->getKey(),
            'scopes' => [],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ])->save();

        Passport::refreshToken()->forceFill([
            'id' => Str::random(80),
            'access_token_id' => $tokenId,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ])->save();

        return $tokenId;
    }

    private function authCode(PassportUser $user, Client $client): string
    {
        $codeId = Str::random(80);

        Passport::authCode()->forceFill([
            'id' => $codeId,
            'user_id' => $user->getKey(),
            'client_id' => $client->getKey(),
            'scopes' => '[]',
            'revoked' => false,
            'expires_at' => now()->addMinutes(10),
        ])->save();

        return $codeId;
    }

    private function tokenRevoked(string $tokenId): bool
    {
        return (bool) Passport::token()->newQuery()->whereKey($tokenId)->value('revoked');
    }

    private function refreshTokenRevoked(string $accessTokenId): bool
    {
        return (bool) Passport::refreshToken()->newQuery()->where('access_token_id', $accessTokenId)->value('revoked');
    }

    private function authCodeRevoked(string $codeId): bool
    {
        return (bool) Passport::authCode()->newQuery()->whereKey($codeId)->value('revoked');
    }
}
