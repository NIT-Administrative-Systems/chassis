# Changelog

All notable changes to [Chassis](https://packagist.org/packages/northwestern-sysdev/chassis) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [v1.5.0] - 2026-10-07

### Added

- Added `DetectUnknownOAuthClient`, a middleware for Passport's routes that throws `UnknownOAuthClientException` (400) when a person reaches the authorization screen from a client that's been deleted or revoked. Passport answers that request with its 401 `invalid_client` JSON, which the person sees in their browser; a self-registered client, such as an MCP client, keeps its client ID after the server deletes it and keeps sending people there. The application renders the exception as a page. The token endpoint still answers clients with JSON, and a redirect URI the client didn't register still gets Passport's response.
- Added `OAuthRedirectTarget`, the destination a consent screen shows for a redirect URI: its scheme, host and port, the punycode form of an internationalized domain that could imitate another site's name, and whether it's an application on the person's own computer.

### Changed

- `OAuthRedirectUri` refuses a redirect URI whose host isn't a valid domain name or IP address, such as one with a right-to-left override character or one that decodes to `/` or `@`, which a consent screen would show as a different site.

## [v1.4.1] - 2026-10-06

### Fixed

- `toBeAccessible()` and `Accessibility::violations()` settle transitions and animations while axe runs. A color partway through a transition could fail contrast that the settled color passes, so a page passed or failed depending on timing.

## [v1.4.0] - 2026-10-06

### Added

- Added `Testing\Browser`, for applications that test with Pest's browser plugin (`pestphp/pest-plugin-browser` `^4.1` or `^5.0`, suggested). It runs in the test and registers nothing in the application.
  - `toBeAccessible()` runs axe with excluded selectors, disabled rules and a minimum impact, which the plugin's `assertNoAccessibilityIssues()` doesn't take. `Accessibility::configure()` sets a suite's defaults.
  - `toHaveNoClientErrors()` fails on `console.error()`, uncaught errors, unhandled promise rejections and failed Livewire requests. The plugin's own check sees only uncaught errors. `ClientErrors::capture()` starts recording before the page loads.
  - `toHaveNoServerErrors()` fails when the application reported an exception while serving the browser, through `Exceptions::fake()`, which the `InteractsWithBrowser` trait sets up.
  - `toBeHealthy()` combines them with a broken-image check and lists every problem at once; `toBeHealthyInEachTheme()` and `toBeHealthyOnMobile()` check light and dark mode and a phone's width; `toAllBeHealthy()` checks a list of paths.
  - `FilamentPages::in()` lists the pages of a Filament panel the signed-in person can open, so every page can be checked without a hand-kept list.
  - `ErrorPages::path()` registers a test route that answers with an error status.
  - `LivewireRequests::settle()` waits out debounced fields and Livewire requests in flight.
  - `FilamentPage` presses buttons by accessible name, opens the user menu, confirms and cancels modals, and waits for notifications and field errors.
- Added `RecordsCustomAudits`, which records an audit for an event that isn't an attribute change, and `PrunesAfterRetentionPeriod`, which prunes records older than a configured number of days and keeps them forever when the setting is null.
- Added `RequireSecretToken`, a middleware that requires an `X-Secret-Token` header matching a config value and refuses every request while that value is empty.
- Added `Passport\OAuthClientRepository`, which treats a `client_id` that isn't a UUID as an unknown client without a query, so Passport answers 401 `invalid_client` instead of a PostgreSQL error. It uses Passport's own lookup when `Passport::$clientUuids` is off.
- Added the `OAuthRedirectUri` validation rule for OAuth redirect URIs, with custom schemes for desktop clients.
- Added `TitleCase`, `NorthwesternDateTime` and `CountInWords` for interface text in Northwestern style, and `ShiftHeadings`, a CommonMark extension that moves a document's headings to a chosen level.

### Deprecated

- `restore-env-files` (`RestoreLocalEnvironmentFilesCommand`) undoes a Cypress run's `.env` swap. It keeps working, and now suggests Pest's browser plugin, which runs browser tests in the test process and swaps nothing, with a link to the Northwestern Laravel Starter's testing guide.

## [v1.3.0] - 2026-10-05

### Added

- Added `AuthenticatesPassportTokens`, an abstract middleware for Laravel Passport APIs that replaces `auth:api`. It validates the bearer token through Passport's resource server, records a failure reason when it refuses one, records the principal, client ID, token ID, scopes and grant type for every grant (including client credentials, where Passport's guard has no user), lets a client-credentials token act as its client's owner, and offers `allowedIps()`, `isEligible()`, `clientOwner()`, `allowsClientsWithoutUser()` and `authenticated()` hooks. A client-credentials token whose client's owner no longer exists is refused. `rateLimitKey()` keys a limiter by client, user or IP.
- Added `LogsPassportRequests`, which logs client-only requests that `LogsApiRequests` skips and adds `principal_type`, `oauth_client_id`, `oauth_token_id`, `oauth_grant_type` and `oauth_scopes` to each entry.
- Added `Passport\AccessRevoker`, which revokes a user's access tokens, their refresh tokens and authorization codes for one client or all clients, without touching shared clients.
- Added `Passport\ExpiringAccessTokenRepository`, which also rejects access tokens whose `expires_at` has passed, for per-token expiry.
- Added `ApiPrincipalType` and `OAuthGrantType` enums, and `ApiRequestContext` keys for the principal type and OAuth client, token, scopes and grant type.
- Added an `exceptPaths` constructor argument to `ProblemDetailsRenderer` for routes, such as OAuth and MCP endpoints, that must keep their protocol's own error bodies.
- Added protected `hasLoggableIdentity()` and `additionalLogData()` hooks to `LogsApiRequests`. `additionalLogData()` receives the request and response, for values only they hold. Their defaults keep its behavior and log entries unchanged.
- `EnsureFeatureEnabled` takes an optional second parameter: `404` answers Not Found instead of 503 while the feature is off, for a feature that should look absent (`EnsureFeatureEnabled::class . ':mcp.enabled,404'`).

### Changed

- `AuthenticatesAccessTokens::isIpAllowed()` and `reportMissingIp()` moved into the `ChecksAllowedIps` trait, which the middleware uses. Their signatures and behavior are unchanged, so subclasses that override them keep working.
- `laravel/passport` is suggested, not required. Nothing in Chassis registers or configures Passport.

## [v1.2.1] - 2026-10-03

### Added

- Added `AuthenticatesAccessTokens::userIdForRateLimiting(Request $request): ?int`, which resolves the bearer token's user ID through the middleware's own `hashToken()` and `findActiveToken()` so rate limiters can bucket by API user. The `api` limiter runs before authentication, so `$request->user()` is null there and limits fell back to the client IP, putting every integration behind one IP in the same bucket. The method returns null for a missing, malformed, invalid, or expired token, records no usage, writes no context, and skips the IP allowlist check. The resolved token is memoized on the request, so authentication does not look it up again. See the README for the limiter setup.
- Added the `client-error` case to `ApiRequestFailure`.

### Fixed

- `ProblemDetailsRenderer::render()` now returns null for `HttpResponseException`, so Laravel sends the exception's own response. Previously it fell through to the generic 500, so a named rate limiter with a custom `->response()` returned a 500 to over-limit API clients instead of the limiter's 429, as did any `abort($response)` thrown from middleware on an API route.
- `ProblemDetailsRenderer` now records `client-error` instead of `server-error` as the failure reason for HTTP exceptions with a status below 500 that have no more specific mapping, such as `abort(402)` or `abort(410)`. 5xx statuses still record `server-error`.

## [v1.2.0] - 2026-10-02

### Added

- Added `SentryTunnelController`, which relays Sentry browser SDK envelopes through the application's origin. It forwards an envelope only when its DSN matches the configured `sentry.dsn` (same host, port, public key, path prefix and project), always sends it to the configured DSN's host, and rejects everything else with a 403. Applications that report browser errors to a different DSN can override `configuredDsn()`. This replaces the tunnel in `northwestern-sysdev/northwestern-laravel-ui`, which forwarded envelopes for any Sentry project. Chassis registers no route macro, because a same-named macro would clash with `northwestern-laravel-ui` in applications that still use it, so applications register the route themselves.

## [v1.1.3] - 2026-07-21

### Added

- Added a protected `discoverPaths()` hook to `ValidateConfigurationCommand` so applications can point validator discovery at a custom directory without copying the whole `handle()` method.

### Changed

- `IdempotentSeederResolver` now throws a `RuntimeException` when a scanned class carries the `#[AutoSeed]` attribute but does not implement `IdempotentSeederInterface` or is abstract, instead of silently skipping it. This is intentionally stricter: it only breaks configurations that were already broken, where a tagged seeder was silently never running. Untagged classes in scanned directories are still ignored.

### Fixed

- Memoized `AutomaticallyOrderedScope` column-existence checks per connection and table for the process lifetime, so automatically ordered models no longer issue uncached `information_schema` queries on every query (including eager loads). `AutomaticallyOrderedScope::flushCache()` is available for tests that migrate mid-process.
- Reworded the Windows PostgreSQL home-directory error in `ConfigurableDbDumperFactory` to reference the `db-snapshots.pg_bin_directory` config key instead of assuming a `PG_BIN_DIRECTORY` env var, since applications map their own env vars to that key.
- Corrected the `SentryExceptionHandler` docblock, which claimed the default user context sends id + email; the default sends only the auth identifier (no behavior change).

## [v1.1.2] - 2026-07-02

### Changed

- Sped up database snapshot schema validation on large projects by excluding `vendor`, `node_modules`, and other heavy directories from the seeder file scan instead of walking and filtering them, and by reusing the collected file list when calculating the checksum rather than scanning the filesystem twice. This resolves long validation times reported on Windows.

## [v1.1.1] - 2026-06-18

### Fixed

- Allowed database snapshot commands to resolve PostgreSQL utilities from `PATH` on Linux CI runners when no `pg_bin_directory` is configured.

## [v1.1.0] - 2026-06-18

### Added

- Added `DatabasePausedDetector` for identifying Aurora Serverless v2 scale-to-zero connection wake timeouts across PDO, Laravel query, and Blade view exception wrappers.

### Removed

- Removed the legacy `chassis:migrate` adoption command, migration steps, and `ChassisNamespaceRector` rename map now that supported internal applications have already adopted Chassis.

## [v1.0.0] - 2026-04-27

Initial stable release.

## [v1.0.0-rc.3] - 2026-04-27

### Fixed

- Registered chassis commands during Cypress's HTTP artisan bridge so snapshot commands like `db:snapshot:create` are available outside normal CLI boot.

## [v1.0.0-rc.2] - 2026-04-27

### Fixed

- Updated `chassis:migrate` to rewrite app `openapi:generate` scripts so they continue scanning chassis OpenAPI annotations after migration.
- Prevented migrated apps from dropping shared `ProblemDetails` and `ValidationProblemDetails` schemas when regenerating `docs/schemas/api-schema.yaml`.

## [v1.0.0-rc.1] - 2026-04-27

Initial extraction of the [Northwestern Laravel Starter](https://laravel-starter.entapp.northwestern.edu/)'s framework utilities into a standalone Composer package.

[Unreleased]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.5.0...HEAD
[v1.5.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.4.1...v1.5.0
[v1.4.1]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.4.0...v1.4.1
[v1.4.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.3.0...v1.4.0
[v1.3.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.2.1...v1.3.0
[v1.2.1]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.2.0...v1.2.1
[v1.2.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.1.3...v1.2.0
[v1.1.3]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.1.2...v1.1.3
[v1.1.2]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.1.1...v1.1.2
[v1.1.1]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.1.0...v1.1.1
[v1.1.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0...v1.1.0
[v1.0.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.3...v1.0.0
[v1.0.0-rc.3]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.2...v1.0.0-rc.3
[v1.0.0-rc.2]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.1...v1.0.0-rc.2
[v1.0.0-rc.1]: https://github.com/NIT-Administrative-Systems/chassis/releases/tag/v1.0.0-rc.1
