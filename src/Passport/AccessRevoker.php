<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Disconnects a user from OAuth clients by revoking what Passport issued to them: access
 * tokens, the refresh tokens issued with them, and unredeemed authorization codes.
 *
 * Revoking a Passport access token leaves its refresh token valid, so revoking access
 * tokens alone does not disconnect anyone. The client itself is never touched: other users
 * may share it.
 *
 * Refresh tokens are found through their access tokens, because Passport stores no user or
 * client on a refresh token. Keep `passport:purge` from deleting access tokens until their
 * refresh tokens have expired (`--hours` at least the refresh token lifetime), or a refresh
 * token whose access token was purged can't be found here.
 */
class AccessRevoker
{
    /**
     * Revoke the user's access to one client. Returns the number of access tokens revoked.
     */
    public function revokeClient(Authenticatable $user, Client|string $client): int
    {
        $clientId = $client instanceof Client ? $client->getKey() : $client;

        return $this->revoke($user, is_string($clientId) || is_int($clientId) ? (string) $clientId : '');
    }

    /**
     * Revoke the user's access to every client. Returns the number of access tokens revoked.
     */
    public function revokeAll(Authenticatable $user): int
    {
        return $this->revoke($user, null);
    }

    private function revoke(Authenticatable $user, ?string $clientId): int
    {
        $userId = $user->getAuthIdentifier();

        return DB::connection(Passport::token()->getConnectionName())->transaction(function () use ($userId, $clientId): int {
            $tokens = Passport::token()->newQuery()
                ->where('user_id', $userId)
                ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId));

            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', (clone $tokens)->select('id'))
                ->where('revoked', false)
                ->update(['revoked' => true]);

            Passport::authCode()->newQuery()
                ->where('user_id', $userId)
                ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
                ->where('revoked', false)
                ->update(['revoked' => true]);

            return $tokens->where('revoked', false)->update(['revoked' => true]);
        });
    }
}
