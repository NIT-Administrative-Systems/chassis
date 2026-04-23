# Chassis

[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-red)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

Framework utilities for Laravel applications, extracted from Northwestern University's [Laravel Starter](https://laravel-starter.entapp.northwestern.edu/). Chassis packages the infrastructure shared across starter apps: audit-aware models, idempotent seeders, API error handling, database snapshotting, configuration validation, and deployment tooling. Applications consume chassis as a versioned dependency and pull improvements without re-merging the starter.

> [!NOTE]
> Chassis is meant for applications built on the [Northwestern Laravel Starter](https://laravel-starter.entapp.northwestern.edu/). Most of the conceptual docs live on the starter's docs site; this README links out rather than duplicating them.

## Installation

```bash
composer require northwestern-sysdev/chassis
```

## Features

### Models and Auditing

- **`BaseModel`** replaces `Illuminate\Database\Eloquent\Model` as your model base. It wires in audit logging (via the `Auditable` trait) and attribute-driven global scopes (via the `HasAutomaticOrdering` trait).
- **`#[AutomaticallyOrdered]`** gives you declarative model ordering (defaults to `order_index asc, label asc`). Tune it with the `primary`, `secondary`, and `*Direction` parameters. Works on any model that uses the `HasAutomaticOrdering` trait — models extending `BaseModel` pick it up automatically; other models (e.g. `User` extending `Authenticatable`) apply the trait directly.
- **`Auditable` trait** enriches [`owen-it/laravel-auditing`](https://github.com/owen-it/laravel-auditing) records with API trace IDs, Livewire component names, and impersonator user IDs (when [`lab404/laravel-impersonate`](https://github.com/404labfr/laravel-impersonate) is installed). Used by `BaseModel`; apply directly to any model that can't extend it.

→ See [Audit Logging](https://laravel-starter.entapp.northwestern.edu/features/audit-logging/) and [Framework Defaults › Eloquent Behavior](https://laravel-starter.entapp.northwestern.edu/architecture/framework-defaults/#eloquent-behavior).

### Idempotent Seeders

Rerunnable, production-safe seeders with auto-discovery and dependency resolution.

```php
use Northwestern\SysDev\Chassis\Attributes\AutoSeed;
use Northwestern\SysDev\Chassis\Seeding\IdempotentSeeder;

#[AutoSeed(dependsOn: [RoleSeeder::class])]
class PermissionSeeder extends IdempotentSeeder
{
    protected string $model = Permission::class;
    protected string $slugColumn = 'slug';

    public function data(): array
    {
        return [
            ['slug' => 'users.view', 'label' => 'View Users'],
            ['slug' => 'users.edit', 'label' => 'Edit Users'],
        ];
    }
}
```

Chassis upserts on `slugColumn`, restores soft-deleted rows instead of duplicating them, and cleans up orphans when you opt in. At boot, it validates the dependency graph and runs seeders in topological order.

For seeders that need more (syncing relationships, dispatching events per row), override `afterUpsert(Model $model, array $row)` and declare any non-column keys in `$transient = [...]`. For bespoke seeders that can't fit the base class shape, `use Northwestern\SysDev\Chassis\Seeding\Concerns\PerformsIdempotentUpserts` and `CleansUpOrphans` directly on a class that implements `IdempotentSeederInterface`.

→ See [Idempotent Seeding](https://laravel-starter.entapp.northwestern.edu/architecture/idempotent-seeding/).

### API Infrastructure

- **`ProblemDetails`** builds [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html) responses via static constructors: `unauthorized()`, `forbidden()`, `notFound()`, `unprocessableEntity()`, `conflict()`, and more. It attaches the current trace ID for you.
- **`ProblemDetailsRenderer`** maps 40+ exception types to RFC 9457 JSON (`ValidationException`, `AuthenticationException`, `PDOException`, the HTTP exceptions, etc.). It only fires on `/api/*` or requests that negotiate JSON. Subclass it and override `mapCustomExceptions()` to add app-specific exception types without re-implementing the defaults.
- **`AuthenticatesAccessTokens` middleware** is an abstract base for Bearer token auth with hash verification, CIDR-aware IP allowlisting, and usage recording. Subclass it and implement `findActiveToken(): ?AccessTokenContract` and `hashToken()`. Usage recording, IP allowlisting, and expiration checks come from the `AccessTokenContract` your token model implements.
- **`LogsApiRequests` middleware** captures trace ID, user, token, status, duration, and request/response sizes. It logs every failure and samples successes at a configurable rate. The trace ID goes out on the `X-Trace-Id` header.
- **`EnvironmentLockdown` middleware** restricts non-production environments to authorized users.
- **`EnsureFeatureEnabled` middleware** returns 503 when a config flag is off: `Route::middleware(EnsureFeatureEnabled::class . ':api.enabled')`.
- **`AccessTokenContract`** is the interface your token model implements to plug into the middleware.

Extending `ProblemDetailsRenderer` for a domain exception:

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

Return `null` to fall through to the built-in match. The hook runs after the API-request gate, so you don't need to re-check `$request->is('api/*')`. Use `setFailure($reason)` to tag the request with a reason string for request logging.

→ See [API](https://laravel-starter.entapp.northwestern.edu/features/api/) and [RFC 9457 defaults](https://laravel-starter.entapp.northwestern.edu/architecture/framework-defaults/#rfc-9457-problem-details-for-api).

### Configuration Validation

Auto-discovered health checks for your app's config.

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

Run them with `php artisan config:validate`. Each validator runs in parallel behind a spinner and reports pass, fail, or skip with remediation hints.

→ See [config:validate](https://laravel-starter.entapp.northwestern.edu/reference/commands/#configvalidate).

### Database Snapshots

Schema-checksum-aware wrappers around [`spatie/laravel-db-snapshots`](https://github.com/spatie/laravel-db-snapshots). Chassis tags each snapshot with a hash of the migration and seeder files that produced it, so restores warn when the current schema has drifted.

```bash
php artisan db:snapshot:create baseline
php artisan db:snapshot:list
php artisan db:snapshot:restore baseline
php artisan db:snapshot:info baseline
php artisan db:snapshot:delete baseline
```

Install `spatie/laravel-db-snapshots` to enable these commands. Non-production only.

→ See [Database Snapshots](https://laravel-starter.entapp.northwestern.edu/features/database-snapshots/).

### Console Utilities

- **`db:rebuild`** does a fresh migrate + seed and clears cache, queue, and schedule. Override `appendSteps()` to add app-specific work (demo data, IDE helpers, etc.); override `steps()` and compose with `baseSteps()` if you need full control over ordering.
- **`db:wake`** polls the database connection with retry and backoff, for serverless Postgres and MySQL cold starts. Options: `--max-attempts`, `--delay`.
- **`db:seed:list`** lists discovered `#[AutoSeed]` seeders with their dependency graph. Supports `--show-dependencies`, `--mermaid`, and `--json`.
- **`config:validate`** runs every `#[ValidatesConfig]` validator (see above).
- **`restore-env-files`** restores local-only environment files after a clean checkout.
- **`chassis:migrate`** migrates a pre-chassis starter app onto chassis namespaces using Rector. See [Migrating an existing app](#migrating-an-existing-app).
- **`RunsSteps` trait** lets you build your own multi-step commands with spinners, immediate feedback, and a summary table. Both `db:rebuild` and `chassis:migrate` use it.

→ Full command reference: [laravel-starter.entapp.northwestern.edu/reference/commands](https://laravel-starter.entapp.northwestern.edu/reference/commands/).

### Other Utilities

- **`@datetime` Blade directive** renders a timestamp in the current user's timezone, resolved from `auth()->user()?->timezone`. The injectable `DateTimeFormatter` service backs it for non-Blade use.
- **`ValidIpOrCidrRule`** validates IPv4/IPv6 addresses or CIDR ranges. Use it as an Illuminate rule or call `ValidIpOrCidrRule::isValid($value)` directly.
- **`SentryExceptionHandler`** reports to Sentry with enriched user context. Requires the suggested `sentry/sentry-laravel`. Override `userContext()` to customize the attributes you send.
- **`ApiRequestContext`** holds the well-known context keys (`TRACE_ID`, `USER_ID`, `TOKEN_ID`, `FAILURE_REASON`) shared between middleware, logging, and exception handling.
- **`ApiRequestFailure` enum** categorizes API failure modes with Filament-compatible labels, descriptions, and icons.

## Artisan Command Reference

| Command | Purpose |
| --- | --- |
| `config:validate` | Run every registered [`ConfigValidator`](https://laravel-starter.entapp.northwestern.edu/reference/commands/#configvalidate). |
| `db:rebuild` | Fresh migrate + seed with cache clearing. Non-production only. |
| `db:seed:list` | List discovered `#[AutoSeed]` seeders, optionally as a Mermaid graph. |
| `db:wake` | Wake a cold database connection with retry. |
| `db:snapshot:create {name?}` | Create a schema-tagged snapshot. |
| `db:snapshot:restore {name?}` | Restore a snapshot. Warns on schema drift. |
| `db:snapshot:list` | List available snapshots. |
| `db:snapshot:info {name}` | Show snapshot metadata and schema checksum. |
| `db:snapshot:delete {name}` | Delete a snapshot and its metadata. |
| `restore-env-files` | Restore local environment files. |
| `chassis:migrate` | Migrate a pre-chassis starter app onto chassis namespaces. |

Chassis is careful about command registration. If your app still has the pre-migration starter version of a command, chassis skips registering its own. Post-migration subclasses take over through Laravel's command discovery.

## Optional Packages

Some features are gated behind suggested packages so you only pull what you use.

| Package | Enables |
| --- | --- |
| [`spatie/laravel-db-snapshots`](https://github.com/spatie/laravel-db-snapshots) | `db:snapshot:*` commands |
| [`sentry/sentry-laravel`](https://github.com/getsentry/sentry-laravel) | `SentryExceptionHandler` |
| [`lab404/laravel-impersonate`](https://github.com/404labfr/laravel-impersonate) | Impersonator tracking in audit records |

## Migrating an existing app

If your app was generated from the starter before chassis existed, you can move to the package in one pass:

```bash
composer require northwestern-sysdev/chassis
php artisan chassis:migrate
```

The command runs a sequence of Rector and code-mod steps: rewrite namespaces, remove legacy framework files, scaffold subclasses for customized middleware and commands, rewrite middleware references in route files, drop the app-level `@datetime` Blade directive registration (chassis now provides it), upgrade old-style config validators to the `#[ValidatesConfig]` attribute, convert the app's `RebuildDatabaseCommand` into a thin subclass with `appendSteps()`, and clean up PHPUnit exclusions. Every step is idempotent, so you can re-run safely. Review the diff and commit.

`ChassisNamespaceRector` exposes the class-rename map if you want to wire the namespace rewrite into your own Rector config.

## Development

```bash
composer install
composer test         # Pest test suite
composer analyse:php  # PHPStan / Larastan
composer format:php   # Pint
composer rector       # Rector
composer all          # rector + format + analyse
```

## License

MIT. See [LICENSE](LICENSE).
