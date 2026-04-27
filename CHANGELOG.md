# Changelog

All notable changes to [Chassis](https://packagist.org/packages/northwestern-sysdev/chassis) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [v1.0.0-rc.2] - 2026-04-27

### Fixed

- Updated `chassis:migrate` to rewrite app `openapi:generate` scripts so they continue scanning chassis OpenAPI annotations after migration.
- Prevented migrated apps from dropping shared `ProblemDetails` and `ValidationProblemDetails` schemas when regenerating `docs/schemas/api-schema.yaml`.

## [v1.0.0-rc.1] - 2026-04-27

Initial extraction of the [Northwestern Laravel Starter](https://laravel-starter.entapp.northwestern.edu/)'s framework utilities into a standalone Composer package.

[Unreleased]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.2...HEAD
[v1.0.0-rc.2]: https://github.com/NIT-Administrative-Systems/chassis/compare/v1.0.0-rc.1...v1.0.0-rc.2
[v1.0.0-rc.1]: https://github.com/NIT-Administrative-Systems/chassis/releases/tag/v1.0.0-rc.1
