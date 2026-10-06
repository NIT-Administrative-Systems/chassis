<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Passport;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Passport's client repository, for UUID client IDs.
 *
 * Passport looks up whatever `client_id` a request sends. PostgreSQL refuses to compare a UUID
 * column with anything that isn't a UUID, so a malformed `client_id` sent to `/oauth/token`
 * fails with a database error and a 500. Bind this repository and that ID is an unknown client
 * instead, found without a query, and Passport answers 401 `invalid_client`:
 *
 * ```php
 * // AppServiceProvider::register()
 * $this->app->singleton(ClientRepository::class, OAuthClientRepository::class);
 * ```
 *
 * An application that turns off `Passport::$clientUuids` gets Passport's own lookup.
 */
class OAuthClientRepository extends ClientRepository
{
    public function find(string|int $id): ?Client
    {
        if (! Passport::$clientUuids || Str::isUuid((string) $id)) {
            return parent::find($id);
        }

        return null;
    }
}
