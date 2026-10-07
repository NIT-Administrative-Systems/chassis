# Chassis

<a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat&logo=php&logoColor=white" alt="PHP Version"></a>
<a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-F05340?style=flat&logo=laravel&logoColor=white" alt="Laravel Version"></a>
<a href="https://packagist.org/packages/northwestern-sysdev/chassis"><img src="https://img.shields.io/packagist/v/northwestern-sysdev/chassis?style=flat&label=Packagist" alt="Packagist Version"></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-22C55E?style=flat" alt="License"></a>

Shared Laravel infrastructure for application-level framework concerns like audited models, idempotent seeders, API error handling, configuration validation, and snapshot tooling. Chassis serves as the core framework layer for Northwestern University's <a href="https://laravel-starter.entapp.northwestern.edu/">Laravel Starter</a>, while remaining usable in other Laravel applications that want these features without copying boilerplate between projects.

## Features

| Area | Included |
| --- | --- |
| Eloquent foundations | `BaseModel`, `Auditable`, `RecordsCustomAudits`, `PrunesAfterRetentionPeriod`, `HasAutomaticOrdering`, `#[AutomaticallyOrdered]` |
| Seeding | `IdempotentSeeder`, `#[AutoSeed]`, dependency resolution, orphan cleanup helpers |
| API infrastructure | `ProblemDetails`, `ProblemDetailsRenderer`, token and Passport auth middleware, request logging |
| Environment controls | `EnvironmentLockdown`, `EnsureFeatureEnabled`, `RequireSecretToken` |
| Validation | `#[ValidatesConfig]`, `ConfigValidator`, `php artisan config:validate` |
| Database tooling | `db:rebuild`, `db:wake`, schema-aware snapshot commands |
| Interface text | `TitleCase`, `NorthwesternDateTime`, `CountInWords`, `ShiftHeadings` |
| Browser testing | Accessibility checks with exclusions, browser and server error checks, Livewire settling, Filament helpers and page discovery for Pest's browser plugin |
| Misc utilities | `@datetime`, `DateTimeFormatter`, `ValidIpOrCidrRule`, `OAuthRedirectUri`, `OAuthRedirectTarget`, `SentryExceptionHandler`, `SentryTunnelController` |

## Installation

```bash
composer require northwestern-sysdev/chassis
```

## Quick Start

The fastest way to adopt Chassis is to use the parts that remove the most boilerplate first.

### 1. Start new models from `BaseModel`

```php
use Northwestern\SysDev\Chassis\Models\BaseModel;

class Project extends BaseModel
{
    //
}
```

`BaseModel` extends Eloquent's `Model` and wires in audit logging plus attribute-driven automatic ordering.

### 2. Make seeders rerunnable

```php
use Northwestern\SysDev\Chassis\Attributes\AutoSeed;
use Northwestern\SysDev\Chassis\Seeding\IdempotentSeeder;

#[AutoSeed]
class RoleSeeder extends IdempotentSeeder
{
    protected string $model = Role::class;
    protected string $slugColumn = 'slug';

    public function data(): array
    {
        return [
            ['slug' => 'admin', 'label' => 'Admin'],
            ['slug' => 'editor', 'label' => 'Editor'],
        ];
    }
}
```

### 3. Register config validators

```php
use Northwestern\SysDev\Chassis\Attributes\ValidatesConfig;
use Northwestern\SysDev\Chassis\Contracts\ConfigValidator;

#[ValidatesConfig(description: 'Directory Search credentials')]
class DirectorySearchValidator implements ConfigValidator
{
    public function shouldRun(): bool { /* ... */ }
    public function validate(): bool { /* ... */ }
    public function successMessage(): string { /* ... */ }
    public function errorMessage(): string { /* ... */ }
    public function hints(): array { /* ... */ }
}
```

Then run:

```bash
php artisan config:validate
```

### 4. Return consistent API errors

Subclass `ProblemDetailsRenderer` to keep Chassis' default exception mapping and layer in your domain-specific cases:

```php
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Northwestern\SysDev\Chassis\Exceptions\ProblemDetailsRenderer;
use Northwestern\SysDev\Chassis\Http\Responses\ProblemDetails;
use Throwable;

class AppProblemDetailsRenderer extends ProblemDetailsRenderer
{
    protected function mapCustomExceptions(Throwable $e, Request $request): ?JsonResponse
    {
        return match (true) {
            $e instanceof TokenBudgetExceededException => tap(
                ProblemDetails::tooManyRequests(detail: $e->getMessage()),
                fn () => $this->setFailure('token-budget-exceeded'),
            ),
            default => null,
        };
    }
}
```

## Core Features

### Models and Auditing

- `BaseModel` is the default model base for chassis-aware apps.
- `Auditable` enriches `owen-it/laravel-auditing` records with request context such as trace IDs, Livewire component names, and impersonator IDs when available.
- `#[AutomaticallyOrdered]` adds declarative default ordering, using `order_index asc, label asc` unless you override the columns and directions.
- `HasAutomaticOrdering` lets non-`BaseModel` classes opt into the same behavior.
- `RecordsCustomAudits` adds `recordCustomAudit($event, new: [...], old: [...])` to an auditable model, for events that aren't attribute changes, such as revoking a credential.
- `PrunesAfterRetentionPeriod` deletes a model's records once they're older than the number of days in a config key, when `model:prune` runs. Implement `retentionConfigKey()`; a null setting keeps records forever, so read the env value without an `(int)` cast.

See [Audit Logging](https://laravel-starter.entapp.northwestern.edu/features/audit-logging/) and
[Framework Defaults: Eloquent behavior](https://laravel-starter.entapp.northwestern.edu/architecture/framework-defaults/#eloquent-behavior).

### Idempotent Seeding

Chassis' seeding layer is built for repeated execution across local, CI, staging, and production environments.

- `#[AutoSeed]` marks a seeder for discovery.
- Dependencies are validated and executed in topological order.
- Rows are upserted by your declared slug column.
- Soft-deleted matches are restored instead of duplicated.
- Orphan cleanup is available when you opt in.

For more advanced cases, override `afterUpsert(Model $model, array $row)` and list any non-column keys in `$transient`.
If your seeder cannot extend the base class cleanly, use the lower-level `PerformsIdempotentUpserts` and `CleansUpOrphans` concerns directly.

See [Idempotent Seeding](https://laravel-starter.entapp.northwestern.edu/architecture/idempotent-seeding/).

### API Infrastructure

- `ProblemDetails` builds RFC 9457 responses such as `unauthorized()`, `forbidden()`, `notFound()`, `unprocessableEntity()`, and `conflict()`.
- `ProblemDetailsRenderer` maps framework and infrastructure exceptions to RFC 9457 JSON for API and JSON-negotiated requests.
- `AuthenticatesAccessTokens` is an abstract middleware base for bearer token auth with hashing, IP allowlisting, expiration checks, and usage recording.
  The `api` rate limiter runs before it, so `$request->user()` is still null there. To give each API user its own bucket instead of sharing one per IP, resolve the user with `userIdForRateLimiting()`, which uses your `hashToken()` and `findActiveToken()`, has no side effects, and returns null for a missing, malformed, invalid, or expired token:

  ```php
  // AuthenticateApiToken is your application's AuthenticatesAccessTokens subclass.
  RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by(
      app(AuthenticateApiToken::class)->userIdForRateLimiting($request) ?? $request->ip()
  ));
  ```

  The resolved token is memoized on the request, so authentication does not query it again.
- `LogsApiRequests` records request outcome, timing, size, token, and trace metadata, and emits `X-Trace-Id` on responses.
- `EnvironmentLockdown` restricts non-production environments to authorized users.
- `EnsureFeatureEnabled` short-circuits routes behind config flags.
- `RequireSecretToken` requires an `X-Secret-Token` header matching a config value and refuses every request while that value is empty, unlike Spatie Laravel Health's own middleware, which lets everything through: `RequireSecretToken::class . ':health.secret_token'`.
- `AccessTokenContract` defines the token model hooks the auth middleware relies on.

#### Laravel Passport

With [`laravel/passport`](https://laravel.com/docs/passport) installed, these classes cover the parts of a Passport API that every application otherwise writes itself. Nothing is registered automatically: an application without Passport, or with Passport for something else, is unaffected until it uses them.

- `AuthenticatesPassportTokens` is an abstract middleware that replaces `auth:api` on API routes. It validates the bearer token for every grant and records a failure reason when it refuses one (`invalid-header-format`, `missing-credentials`, `token-invalid-or-expired`, `ip-denied`). For accepted tokens it records the principal (`user` or `client`), client ID, token ID, scopes and grant type under new `ApiRequestContext` keys. A client-credentials token acts as its client's owner when the owner can sign in, so a service integration can be a user with roles that owns its clients. It then sets the user and client on Passport's guard, so `$request->user()`, Passport's `CheckToken` scope middleware and policies work as they do after `auth:api`. Override `allowedIps()` for client IP allowlists, `isEligible()` to refuse deactivated accounts, `clientOwner()` to change the owner rule, `allowsClientsWithoutUser()` to require that every request act for a user, and `authenticated()` to record usage. A client whose owner no longer exists is always refused:

  ```php
  class AuthenticatePassportToken extends AuthenticatesPassportTokens
  {
      protected function allowedIps(Client $client): ?array
      {
          return $client->allowed_ips;
      }

      protected function isEligible(Authenticatable $user): bool
      {
          return ! $user->netid_inactive;
      }
  }
  ```

  `AuthenticatePassportToken::rateLimitKey($request)` gives a limiter that runs after it a key per client for client credentials, per user for every other token, and per IP otherwise.
- `LogsPassportRequests` works like `LogsApiRequests`, and also logs clients acting for themselves, which have no user. Each entry adds `principal_type`, `oauth_client_id`, `oauth_token_id`, `oauth_grant_type` and `oauth_scopes`.
- `Passport\AccessRevoker` disconnects a user from one client (`revokeClient()`) or every client (`revokeAll()`), revoking access tokens, their refresh tokens and authorization codes. Revoking an access token alone leaves its refresh token valid. Keep `passport:purge --hours` at least as long as the refresh token lifetime, because refresh tokens are found through their access tokens.
- `Passport\OAuthClientRepository` treats a `client_id` that isn't a UUID as an unknown client, without a query, so Passport answers 401 `invalid_client` instead of a PostgreSQL error. Bind it over Passport's `ClientRepository`.
- `DetectUnknownOAuthClient` throws `UnknownOAuthClientException` (400) when a person reaches the authorization screen from a client that's been deleted or revoked, which Passport otherwise answers with its 401 `invalid_client` JSON in their browser. A self-registered client, such as an MCP client, keeps its client ID after the server deletes it. Add the middleware to `passport.middleware` and render the exception as a page; the token endpoint still answers clients with JSON.
- `Passport\ExpiringAccessTokenRepository` gives access tokens a per-token expiry. Bind it over Passport's `AccessTokenRepository`, then shorten a token's `expires_at` after creating it; the check runs inside Passport's existing revocation query.
- `ProblemDetailsRenderer` takes `exceptPaths`, such as `['oauth/*', 'mcp/*']`, for routes whose clients expect the protocol's own error bodies.

See [API](https://laravel-starter.entapp.northwestern.edu/features/api/) and
[RFC 9457 defaults](https://laravel-starter.entapp.northwestern.edu/architecture/framework-defaults/#rfc-9457-problem-details-for-api).

### Browser Testing

`Testing\Browser` adds what [Pest's browser plugin](https://pestphp.com/docs/browser-testing) doesn't check yet. It needs `pestphp/pest-plugin-browser` (`^4.1` or `^5.0`) and Playwright, and runs entirely in the test: nothing is registered in the application.

Register the expectations and your suite's accessibility defaults in `tests/Pest.php`, and use `InteractsWithBrowser` on the browser test case so exceptions are faked:

```php
use Northwestern\SysDev\Chassis\Testing\Browser\Accessibility;
use Northwestern\SysDev\Chassis\Testing\Browser\Expectations;

Expectations::register();
Accessibility::configure(disableRules: ['duplicate-id']);
```

| Expectation | Fails when |
| --- | --- |
| `toBeAccessible(exclude: [], disableRules: [])` | axe finds a violation of any impact (configurable), outside the excluded selectors |
| `toHaveNoClientErrors()` | The page logged `console.error()`, threw, left a promise rejection unhandled, or a Livewire request failed |
| `toHaveNoServerErrors()` | The application reported an exception while serving the browser, Livewire updates included |
| `toBeHealthy(exclude: [])` | Any of the above, or an image failed to load; every problem is listed at once |
| `toBeHealthyInEachTheme()`, `toBeHealthyOnMobile()` | The page isn't healthy in light and dark mode, or at a phone's width |
| `toAllBeHealthy(exclude: [])` | Any page in a list of paths isn't healthy; every unhealthy page is listed |

```php
it('every administration page is healthy', function () {
    $this->actingAs(User::factory()->administrator()->create());

    expect(FilamentPages::in('administration'))->toAllBeHealthy();
});

it('an audit record is healthy', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $audit = Audit::factory()->create();

    // A third-party diff viewer whose syntax colors don't meet contrast.
    expect(visit("/administration/audits/{$audit->id}"))->toBeHealthy(exclude: ['diffs-container']);
});
```

Pest's own `assertNoJavaScriptErrors()` sees only uncaught errors, and Alpine and Livewire report through `console.error()`. `ClientErrors` adds a script to the page's browser context and reloads the page once, so errors raised while it loads are recorded too. Call `ClientErrors::capture($page)` before interacting with a page to record everything that follows.

The helpers:

- `FilamentPages::in('admin')` lists the paths of a panel's pages the signed-in person can open: each resource's index and create pages, and each page, cluster and dashboard. Pages that need a record are left out; check those with records made for the test.
- `ErrorPages::path(500)` registers a test route that answers with that status, so error pages only a failure shows can be checked.
- `LivewireRequests::settle($page, debounce: 400)` waits out a debounced field and any Livewire request in flight, so the next read sees the result.
- `FilamentPage::press($page, 'Create')`, `openUserMenu()`, `confirmModal()`, `cancelModal()`, `assertNotified($page, 'Title')` and `assertFieldError($page, 'Label', 'message')` drive Filament 5's markup. Pest's `press()` clicks the first element with that text, which on a Filament page is often a breadcrumb.

### Configuration Validation

`php artisan config:validate` discovers every class implementing `ConfigValidator` that is decorated with `#[ValidatesConfig]`.
Validators run concurrently and report pass, fail, or skip states with remediation hints.

See [Command reference: config:validate](https://laravel-starter.entapp.northwestern.edu/reference/commands/#configvalidate).

### Database Snapshots

Chassis wraps [`spatie/laravel-db-snapshots`](https://github.com/spatie/laravel-db-snapshots) with schema checksums so restores can detect drift between the snapshot's source schema and the current app state.

```bash
php artisan db:snapshot:create baseline
php artisan db:snapshot:list
php artisan db:snapshot:restore baseline
php artisan db:snapshot:info baseline
php artisan db:snapshot:delete baseline
```

Snapshot commands are registered only when `spatie/laravel-db-snapshots` is installed. They are intended for non-production use.

PostgreSQL utilities are resolved from `config('db-snapshots.pg_bin_directory')` when configured. If no directory is configured, Chassis discovers common Herd paths on macOS and Windows, and otherwise falls back to the operating system `PATH`. Linux CI runners should normally install PostgreSQL client tools through the runner image or setup action rather than hard-coding a local path.

See [Database Snapshots](https://laravel-starter.entapp.northwestern.edu/features/database-snapshots/).

### Console Utilities

| Command | Purpose |
| --- | --- |
| `config:validate` | Run all discovered `#[ValidatesConfig]` validators. |
| `db:rebuild` | Fresh migrate and seed, with cache and related cleanup. |
| `db:seed:list` | List discovered `#[AutoSeed]` seeders. Supports dependency output, Mermaid, and JSON. |
| `db:wake` | Retry database connection until a cold or sleeping database is available. |
| `db:snapshot:create {name?}` | Create a schema-tagged database snapshot. |
| `db:snapshot:restore {name?}` | Restore a snapshot and warn if the schema checksum has drifted. |
| `db:snapshot:list` | List saved snapshots. |
| `db:snapshot:info {name}` | Show snapshot metadata and schema checksum details. |
| `db:snapshot:delete {name}` | Delete a snapshot and its metadata. |
| `restore-env-files` | Deprecated. Restores the `.env` files a Cypress run swapped; consider [Pest's browser plugin](https://laravel-starter.entapp.northwestern.edu/guides/testing/) instead. |

`RunsSteps` is also available if you want the same structured spinner + summary experience in your own multi-step Artisan commands.

Full command docs: <https://laravel-starter.entapp.northwestern.edu/reference/commands/>

### Other Utilities

- `@datetime` renders timestamps in the authenticated user's timezone via the `DateTimeFormatter` service.
- `ValidIpOrCidrRule` validates IPv4, IPv6, and CIDR input.
- `OAuthRedirectUri` validates an OAuth redirect URI: HTTPS, HTTP to the loopback address, or one of the custom schemes you allow for desktop clients (RFC 8252). It refuses a URI that PHP and a browser would read as different hosts, such as one with a backslash or whitespace, so a consent screen shows where the browser actually goes.
- `OAuthRedirectTarget::from($redirectUri)` gives a consent screen the destination to show: `display` (scheme, host and port, `https://bücher.example`), `punycode` for an internationalized domain that could imitate another site's name (`https://xn--bcher-kva.example`), and `isLoopback` for an application on the person's own computer.
- `TitleCase::of()` writes names in Chicago headline style, keeping words that already carry capitals ("NetID") and translation placeholders.
- `NorthwesternDateTime` writes dates and times in Northwestern's editorial style ("10:12 a.m. CDT Saturday, October 10", "noon"), and `CountInWords::of(5, 'minute')` writes "five minutes".
- `ShiftHeadings` is a CommonMark extension that moves a document's headings so the shallowest lands at the level you choose: `Str::markdown($text, extensions: [new ShiftHeadings(2)])`.
- `SentryExceptionHandler` enriches Sentry reporting with user context when `sentry/sentry-laravel` is installed.
- `SentryTunnelController` relays Sentry browser SDK envelopes through the application's origin. It only forwards envelopes addressed to the configured `sentry.dsn`, so it can't be used as an open relay. Register it and set the browser SDK's `tunnel` option to the route:

  ```php
  Route::post('sentry/tunnel', SentryTunnelController::class)
      ->withoutMiddleware([PreventRequestForgery::class])
      ->name('sentry.tunnel');
  ```
- `ApiRequestContext` centralizes request context keys shared across middleware, logging, and exception handling.
- `ApiRequestFailure` standardizes API failure labels, descriptions, and icons for UI consumption.

## Optional Packages

Some features stay opt-in so applications only install what they use.

| Package | Enables |
| --- | --- |
| [`spatie/laravel-db-snapshots`](https://github.com/spatie/laravel-db-snapshots) | `db:snapshot:*` commands |
| [`sentry/sentry-laravel`](https://github.com/getsentry/sentry-laravel) | `SentryExceptionHandler` |
| [`lab404/laravel-impersonate`](https://github.com/404labfr/laravel-impersonate) | Impersonator tracking in audit records |
| [`laravel/passport`](https://github.com/laravel/passport) `^13.7` | `AuthenticatesPassportTokens`, `LogsPassportRequests`, `AccessRevoker`, `ExpiringAccessTokenRepository`, `OAuthClientRepository`, `DetectUnknownOAuthClient` |
| [`pestphp/pest-plugin-browser`](https://github.com/pestphp/pest-plugin-browser) `^4.1` or `^5.0` | `Testing\Browser` |

## Development

```bash
composer install
composer test
composer analyse:php
composer format:php
composer rector
composer all
```

The browser tests need Playwright and run on their own:

```bash
npm install
npx playwright install chromium
vendor/bin/pest --testsuite=Browser
```

## License

The MIT License (MIT). See [LICENSE](LICENSE).
