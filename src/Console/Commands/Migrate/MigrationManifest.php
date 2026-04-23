<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate;

/**
 * Central manifest of constants used across migration steps.
 *
 * Keeps file lists, scaffolded class exclusions, and other shared
 * configuration in one place instead of scattered across steps.
 */
final class MigrationManifest
{
    /**
     * Source files to delete (relative to base_path()).
     * These are the files that have been extracted into the package.
     *
     * @var list<string>
     */
    public const array SOURCE_FILES = [
        // Models (BaseModel left for manual migration — too many references)
        'app/Domains/Core/Models/Scopes/AutomaticallyOrderedScope.php',
        'app/Domains/Core/Models/Concerns/Auditable.php',

        // Attributes
        'app/Domains/Core/Attributes/AutomaticallyOrdered.php',
        'app/Domains/Core/Attributes/AutoSeed.php',
        'app/Domains/Core/Attributes/StarterValidator.php',

        // Contracts
        'app/Domains/Core/Contracts/ConfigValidator.php',
        'app/Domains/Core/Contracts/IdempotentSeederInterface.php',

        // Seeding
        'app/Domains/Core/Seeders/IdempotentSeeder.php',
        'app/Domains/Core/Seeders/Concerns/AuditsSeederChanges.php',
        'app/Domains/Core/Services/IdempotentSeederResolver.php',
        'app/Domains/Core/Seeders/DiscoverSeeders.php',
        'app/Domains/Core/Database/ValueObjects/SeederInfo.php',

        // Database / Snapshots
        'app/Domains/Core/Database/SchemaChecksumManager.php',
        'app/Domains/Core/Database/ConfigurableDbDumperFactory.php',
        'app/Domains/Core/Database/ValueObjects/SchemaSnapshot.php',
        'app/Domains/Core/Database/ValueObjects/SnapshotListItem.php',
        'app/Domains/Core/Database/ValueObjects/SchemaFileCollection.php',

        // HTTP / Responses
        'app/Http/Responses/ProblemDetails.php',

        // Exceptions
        'app/Domains/Core/Exceptions/ProblemDetailsRenderer.php',
        'app/Domains/Core/Exceptions/MissingRequestIpForRestrictedTokenException.php',
        'app/Domains/Core/Exceptions/MissingRequestIpForRestrictedToken.php',
        'app/Domains/Core/Exceptions/SentryExceptionHandler.php',

        // Enums
        'app/Domains/Core/Enums/ApiRequestFailure.php',

        // Value Objects
        'app/Domains/Core/ValueObjects/ApiRequestContext.php',
        'app/Domains/Core/ValueObjects/ResolvedValidator.php',

        // Rules
        'app/Domains/Core/Rules/ValidIpOrCidrRule.php',

        // Services
        'app/Domains/Core/Services/ConfigValidatorResolver.php',
        'app/Domains/Core/Services/DateTimeFormatter.php',

        // Console
        'app/Console/Commands/Concerns/RunsSteps.php',
        'app/Console/Commands/ValidateConfigurationCommand.php',
        'app/Console/Commands/AutoSeedListCommand.php',
        // Snapshot commands (newer path: DatabaseSnapshots/ subdirectory)
        'app/Console/Commands/DatabaseSnapshots/DatabaseSnapshotCommand.php',
        'app/Console/Commands/DatabaseSnapshots/CreateDatabaseSnapshotCommand.php',
        'app/Console/Commands/DatabaseSnapshots/RestoreDatabaseSnapshotCommand.php',
        'app/Console/Commands/DatabaseSnapshots/ListDatabaseSnapshotsCommand.php',
        'app/Console/Commands/DatabaseSnapshots/DeleteDatabaseSnapshotCommand.php',
        'app/Console/Commands/DatabaseSnapshots/InfoDatabaseSnapshotCommand.php',
        // Snapshot commands (older path: flat in Commands/)
        'app/Console/Commands/DatabaseSnapshotCommand.php',
        'app/Console/Commands/CreateDatabaseSnapshotCommand.php',
        'app/Console/Commands/RestoreDatabaseSnapshotCommand.php',
        'app/Console/Commands/ListDatabaseSnapshotsCommand.php',
        'app/Console/Commands/DeleteDatabaseSnapshotCommand.php',
        'app/Console/Commands/InfoDatabaseSnapshotCommand.php',

        // Other commands
        'app/Console/Commands/WakeDatabaseCommand.php',
        'app/Console/Commands/RestoreLocalEnvironmentFilesCommand.php',

        // Directories to remove (empty after file extraction)
        'app/Console/Commands/NorthwesternLaravelStarter',

        // Middleware
        'app/Http/Middleware/EnsureApiEnabled.php',
        'app/Http/Middleware/EnvironmentLockdown.php',

        // Security middleware (replaced by scaffolded subclasses)
        'app/Domains/Auth/Http/Middleware/AuthenticatesAccessTokens.php',
        'app/Domains/Auth/Http/Middleware/LogsApiRequests.php',
        'app/Http/Middleware/AuthenticatesApiTokens.php',
        'app/Http/Middleware/AuthenticatesAccessTokens.php',
        'app/Http/Middleware/LogsApiRequests.php',
    ];

    /**
     * Test files to delete (relative to base_path()).
     *
     * @var list<string>
     */
    public const array TEST_FILES = [
        'tests/Unit/Domains/Core/Rules/ValidIpOrCidrRuleTest.php',
        'tests/Unit/Domains/Core/Attributes/AutoSeedTest.php',
        'tests/Unit/Domains/Core/Services/IdempotentSeederResolverTest.php',
        'tests/Feature/Domains/Core/Seeders/DiscoverSeedersTest.php',
        'tests/Unit/Domains/Core/Services/ConfigValidatorResolverTest.php',
        'tests/Unit/Domains/Core/Database/ValueObjects/SeederInfoTest.php',
        'tests/Unit/Domains/Core/Database/ValueObjects/SchemaSnapshotTest.php',
        'tests/Unit/Domains/Core/Database/ValueObjects/SnapshotListItemTest.php',
        'tests/Unit/Domains/Core/Database/ValueObjects/SchemaFileCollectionTest.php',
        'tests/Feature/Http/Responses/ProblemDetailsTest.php',
        'tests/Feature/Domains/Core/Exceptions/ProblemDetailsRendererTest.php',
        'tests/Feature/Domains/Core/Attributes/AutomaticallyOrderedTest.php',
        'tests/Feature/Domains/Core/Models/Concerns/AuditableTest.php',
        'tests/Feature/Domains/Core/Services/DateTimeFormatterTest.php',
        'tests/Feature/Domains/Core/Seeders/Concerns/AuditsSeederChangesTest.php',
        'tests/Feature/Http/Middleware/EnsureApiEnabledTest.php',
        // NOTE: tests/Feature/Http/Middleware/EnvironmentLockdownTest.php is
        // intentionally NOT deleted. The scaffolded subclass at
        // app/Http/Middleware/EnvironmentLockdown.php still needs app-level
        // coverage (the chassis base class only covers the abstract behavior).
        'tests/Feature/Commands/AutoSeedListCommandTest.php',
        'tests/Feature/Commands/WakeDatabaseCommandTest.php',
        'tests/Feature/Commands/RestoreLocalEnvironmentFilesCommandTest.php',
    ];

    /**
     * Classes whose references should NOT be rewritten during namespace
     * migration. These either get scaffolded as local subclasses or are
     * left for developers to migrate manually (to reduce diff size).
     *
     * @var list<string>
     */
    public const array EXCLUDED_FROM_REWRITE = [
        // Scaffolded subclasses — the app keeps its own version
        'App\Http\Middleware\EnvironmentLockdown',
        'App\Domains\Core\Exceptions\SentryExceptionHandler',
        'App\Console\Commands\RebuildDatabaseCommand',

        // BaseModel — too many references across every model. Developers
        // swap to the chassis's BaseModel on their own schedule.
        'App\Domains\Core\Models\BaseModel',
    ];
}
