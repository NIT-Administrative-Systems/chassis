<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\ValueObjects;

/**
 * Shared context keys used throughout the API request lifecycle.
 *
 * These constants act as identifiers for values stored in Laravel's
 * request-scoped Context bag. They allow middleware, exception
 * rendering, and logging layers to exchange metadata without
 * directly depending on each other.
 */
final readonly class ApiRequestContext
{
    /** Unique request-level identifier shared across all logging. */
    public const string TRACE_ID = 'api_trace_id';

    /** ID of the authenticated API user (if authentication succeeded). */
    public const string USER_ID = 'api_user_id';

    /** ID of the authenticated Access Token (if token validation succeeded). */
    public const string TOKEN_ID = 'access_token_id';

    /** Readable failure reason set by authentication or exception handling. */
    public const string FAILURE_REASON = 'api_failure_reason';

    /*
     * Set by AuthenticatesPassportTokens. TRACE_ID, USER_ID and FAILURE_REASON are shared with
     * the access token middleware; TOKEN_ID is not, because Passport token IDs are strings.
     */

    /** Who the request acts for: an ApiPrincipalType value. */
    public const string PRINCIPAL_TYPE = 'api_principal_type';

    /** ID of the OAuth client the access token was issued to. */
    public const string OAUTH_CLIENT_ID = 'api_oauth_client_id';

    /** ID of the OAuth access token (a string). */
    public const string OAUTH_TOKEN_ID = 'api_oauth_token_id';

    /** Scopes granted to the OAuth access token (a list of strings). */
    public const string OAUTH_SCOPES = 'api_oauth_scopes';

    /** How the OAuth access token was obtained: an OAuthGrantType value. */
    public const string OAUTH_GRANT_TYPE = 'api_oauth_grant_type';
}
