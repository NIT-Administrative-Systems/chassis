<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures and persists metadata for API requests authenticated by
 * {@see AuthenticatesPassportTokens}.
 *
 * Works like {@see LogsApiRequests}, and also logs requests from a client acting for
 * itself, which have no user. Each entry gets these keys besides the standard ones:
 *
 * - `principal_type`: `user` or `client`
 * - `oauth_client_id`: the client's ID
 * - `oauth_token_id`: the access token's ID, a string
 * - `oauth_grant_type`: `personal_access`, `authorization_code` or `client_credentials`
 * - `oauth_scopes`: the token's scopes, a list of strings
 *
 * The standard `token_id` key stays null: Passport token IDs are strings, so they are in
 * `oauth_token_id` instead. Map the keys you keep in `persistLog()`.
 */
abstract class LogsPassportRequests extends LogsApiRequests
{
    protected function hasLoggableIdentity(): bool
    {
        return parent::hasLoggableIdentity()
            || Context::get(ApiRequestContext::PRINCIPAL_TYPE) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalLogData(Request $request, Response $response): array
    {
        return [
            'principal_type' => Context::get(ApiRequestContext::PRINCIPAL_TYPE),
            'oauth_client_id' => Context::get(ApiRequestContext::OAUTH_CLIENT_ID),
            'oauth_token_id' => Context::get(ApiRequestContext::OAUTH_TOKEN_ID),
            'oauth_grant_type' => Context::get(ApiRequestContext::OAUTH_GRANT_TYPE),
            'oauth_scopes' => Context::get(ApiRequestContext::OAUTH_SCOPES),
        ];
    }
}
