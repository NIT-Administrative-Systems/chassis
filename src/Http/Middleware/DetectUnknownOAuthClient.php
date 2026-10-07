<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Northwestern\SysDev\Chassis\Exceptions\UnknownOAuthClientException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throws {@see UnknownOAuthClientException} when a person reaches Passport's authorization
 * screen from a client that's been deleted or revoked, so the application can show them a page
 * instead of Passport's 401 `invalid_client` JSON.
 *
 * A client that registered itself, such as an MCP client, keeps its client ID after the server
 * deletes or revokes it, and keeps sending the person back with it. Passport answers with JSON
 * meant for the client, which the person sees in their browser. Only the authorization screen
 * is affected: the token endpoint still answers clients with JSON, and some register again
 * when they see it. Passport also answers `invalid_client` for a redirect URI the client didn't
 * register; that response is kept, because the client still exists.
 *
 * Add it to Passport's routes, and render the exception:
 * ```php
 * // config/passport.php
 * 'middleware' => [DetectUnknownOAuthClient::class],
 *
 * // bootstrap/app.php
 * $exceptions->render(fn (UnknownOAuthClientException $e) => response()->view('oauth.unknown-client', status: $e->getStatusCode()));
 * ```
 */
class DetectUnknownOAuthClient
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED
            && $request->routeIs('passport.authorizations.authorize')
            && $this->isInvalidClientError($response)
            && ! $this->isActiveClient($request->query('client_id'))) {
            throw new UnknownOAuthClientException();
        }

        return $response;
    }

    private function isInvalidClientError(Response $response): bool
    {
        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) && ($body['error'] ?? null) === 'invalid_client';
    }

    /**
     * Whether the client exists and isn't revoked. A malformed UUID is unknown, without the
     * query that PostgreSQL would refuse.
     */
    private function isActiveClient(mixed $clientId): bool
    {
        if (! is_string($clientId) || $clientId === '' || (Passport::$clientUuids && ! Str::isUuid($clientId))) {
            return false;
        }

        return resolve(ClientRepository::class)->findActive($clientId) !== null;
    }
}
