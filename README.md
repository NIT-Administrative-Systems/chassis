# Chassis

<a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat&logo=php&logoColor=white" alt="PHP Version"></a>
<a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-F05340?style=flat&logo=laravel&logoColor=white" alt="Laravel Version"></a>
<a href="https://packagist.org/packages/northwestern-sysdev/chassis"><img src="https://img.shields.io/packagist/v/northwestern-sysdev/chassis?style=flat&label=Packagist" alt="Packagist Version"></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-22C55E?style=flat" alt="License"></a>

Shared Laravel infrastructure for application-level framework concerns like audited models, idempotent seeders, API error handling, configuration validation, snapshot tooling, and migration helpers. Chassis serves as the core framework layer for Northwestern University's <a href="https://laravel-starter.entapp.northwestern.edu/">Laravel Starter</a>, while remaining usable in other Laravel applications that want these features without copying boilerplate between projects.

## Features

| Area | Included |
| --- | --- |
| Eloquent foundations | `BaseModel`, `Auditable`, `HasAutomaticOrdering`, `#[AutomaticallyOrdered]` |
| Seeding | `IdempotentSeeder`, `#[AutoSeed]`, dependency resolution, orphan cleanup helpers |
| API infrastructure | `ProblemDetails`, `ProblemDetailsRenderer`, token auth middleware, request logging |
| Environment controls | `EnvironmentLockdown`, `EnsureFeatureEnabled` |
| Validation | `#[ValidatesConfig]`, `ConfigValidator`, `php artisan config:validate` |
| Database tooling | `db:rebuild`, `db:wake`, schema-aware snapshot commands |
| App migration | `php artisan chassis:migrate`, Rector namespace rewrite support |
| Misc utilities | `@datetime`, `DateTimeFormatter`, `ValidIpOrCidrRule`, `SentryExceptionHandler` |

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
- `LogsApiRequests` records request outcome, timing, size, token, and trace metadata, and emits `X-Trace-Id` on responses.
- `EnvironmentLockdown` restricts non-production environments to authorized users.
- `EnsureFeatureEnabled` short-circuits routes behind config flags.
- `AccessTokenContract` defines the token model hooks the auth middleware relies on.

See [API](https://laravel-starter.entapp.northwestern.edu/features/api/) and
[RFC 9457 defaults](https://laravel-starter.entapp.northwestern.edu/architecture/framework-defaults/#rfc-9457-problem-details-for-api).

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
| `restore-env-files` | Restore local-only environment files after a clean checkout. |
| `chassis:migrate` | Migrate a pre-chassis starter app onto package namespaces and base classes. |

`RunsSteps` is also available if you want the same structured spinner + summary experience in your own multi-step Artisan commands.

Full command docs: <https://laravel-starter.entapp.northwestern.edu/reference/commands/>

### Other Utilities

- `@datetime` renders timestamps in the authenticated user's timezone via the `DateTimeFormatter` service.
- `ValidIpOrCidrRule` validates IPv4, IPv6, and CIDR input.
- `SentryExceptionHandler` enriches Sentry reporting with user context when `sentry/sentry-laravel` is installed.
- `ApiRequestContext` centralizes request context keys shared across middleware, logging, and exception handling.
- `ApiRequestFailure` standardizes API failure labels, descriptions, and icons for UI consumption.

## Optional Packages

Some features stay opt-in so applications only install what they use.

| Package | Enables |
| --- | --- |
| [`spatie/laravel-db-snapshots`](https://github.com/spatie/laravel-db-snapshots) | `db:snapshot:*` commands |
| [`sentry/sentry-laravel`](https://github.com/getsentry/sentry-laravel) | `SentryExceptionHandler` |
| [`lab404/laravel-impersonate`](https://github.com/404labfr/laravel-impersonate) | Impersonator tracking in audit records |

## Migrating an Existing Starter App

If your application was generated from the Northwestern Laravel Starter before Chassis existed, migrate it in one pass:

```bash
composer require northwestern-sysdev/chassis
php artisan chassis:migrate
```

The migration command is intentionally broad. It can:

- Rewrite extracted starter namespaces to package namespaces
- Remove legacy copied framework files now provided by Chassis
- Scaffold app-level subclasses where project-specific overrides still belong
- Rewrite middleware references in route files
- Replace old config validator patterns with `#[ValidatesConfig]`
- Remove app-level `@datetime` directive registration
- Convert rebuild command customizations into the new extension points
- Clean up PHPUnit exclusions related to extracted files

The command is idempotent. Re-running it is safe, and reviewing the diff before committing is still the right workflow.

If you want only the namespace rewrite behavior, `ChassisNamespaceRector` exposes the class rename map for custom Rector configs.

## Development

```bash
composer install
composer test
composer analyse:php
composer format:php
composer rector
composer all
```

## License

The MIT License (MIT). See [LICENSE](LICENSE).
