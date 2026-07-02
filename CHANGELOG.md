# Changelog

All notable changes to [Chassis](https://packagist.org/packages/northwestern-sysdev/chassis) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.1.2...HEAD
[v1.1.2]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.1.1...v1.1.2
[v1.1.1]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.1.0...v1.1.1
[v1.1.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0...v1.1.0
[v1.0.0]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.3...v1.0.0
[v1.0.0-rc.3]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.2...v1.0.0-rc.3
[v1.0.0-rc.2]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.1...v1.0.0-rc.2
[v1.0.0-rc.1]: https://github.com/NIT-Administrative-Systems/chassis/releases/tag/v1.0.0-rc.1
