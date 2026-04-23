<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Snapshots;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Concerns\RunsSteps;
use Northwestern\SysDev\Chassis\Database\SchemaChecksumManager;
use Northwestern\SysDev\Chassis\Database\ValueObjects\SchemaFileCollection;
use Northwestern\SysDev\Chassis\Database\ValueObjects\SchemaSnapshot;
use RuntimeException;

/**
 * Creates a database snapshot with schema validation.
 *
 * This command wraps `spatie/laravel-db-snapshots` and adds schema validation
 * to ensure snapshots are compatible with the current codebase state.
 *
 * Requirements:
 * - PostgreSQL: `pg_dump` and `psql` CLI utilities must be in your `$PATH`
 */
class CreateDatabaseSnapshotCommand extends DatabaseSnapshotCommand
{
    use RunsSteps;

    protected $signature = 'db:snapshot:create
                            {filename? : The name of the snapshot file to generate}
                            {--skip-schema-validation : Skip schema validation checks}';

    protected $description = 'Creates a database snapshot with schema validation';

    public function __construct(
        private readonly SchemaChecksumManager $schemaManager,
    ) {
        parent::__construct();
    }

    protected function successMessage(): string
    {
        return 'Snapshot created successfully';
    }

    public function handle(): int
    {
        if (App::isProduction()) {
            $this->components->error('This command cannot be run in production.');

            return self::FAILURE;
        }

        /** @var string|null $filenameArg */
        $filenameArg = $this->argument('filename');
        $snapshotName = $this->normalizeSnapshotName($filenameArg);
        $snapshotPath = self::snapshotPath($snapshotName);

        $this->newLine();
        $this->components->info('Creating Database Snapshot');

        /** @var SchemaFileCollection|null $schemaFiles */
        $schemaFiles = null;
        /** @var non-empty-string|null $checksum */
        $checksum = null;

        if (! $this->option('skip-schema-validation')) {
            if (! $this->runStep('Collecting schema information', function () use (&$schemaFiles): void {
                $schemaFiles = $this->schemaManager->collectSchemaFiles();
            })) {
                $this->displaySummary();

                return self::FAILURE;
            }

            if (! $this->runStep('Calculating schema checksum', function () use (&$checksum): void {
                $checksum = $this->schemaManager->calculateCurrentCodebaseChecksum();
            })) {
                $this->displaySummary();

                return self::FAILURE;
            }
        }

        if (! $this->runStep('Creating snapshot directory', function () use ($snapshotPath): void {
            $snapshotDir = dirname($snapshotPath);
            if (! File::exists($snapshotDir) && ! File::makeDirectory($snapshotDir, recursive: true)) {
                throw new RuntimeException("Unable to create snapshot directory: {$snapshotDir}");
            }
        })) {
            $this->displaySummary();

            return self::FAILURE;
        }

        if (! $this->runStep('Creating database dump', function () use ($snapshotName): void {
            $result = $this->callSilently('snapshot:create', ['name' => $snapshotName]);
            if ($result !== self::SUCCESS) {
                throw new RuntimeException('Database dump failed');
            }
        })) {
            $this->cleanupFailedSnapshot($snapshotPath);
            $this->displaySummary();

            return self::FAILURE;
        }

        if (! $this->option('skip-schema-validation') && ! $this->runStep(
            'Saving schema metadata',
            function () use ($snapshotName, $checksum, $schemaFiles): void {
                if ($checksum === null || $checksum === '' || $schemaFiles === null) {
                    throw new RuntimeException('Schema validation steps did not complete successfully.');
                }

                if ($snapshotName === '') {
                    throw new RuntimeException('Snapshot name cannot be empty.');
                }

                $snapshot = new SchemaSnapshot(
                    name: $snapshotName,
                    checksum: $checksum,
                    createdAt: now(),
                    migrationCount: count($schemaFiles->migrations),
                    seederCount: count($schemaFiles->seeders)
                );

                $this->schemaManager->saveSnapshot($snapshotName, $snapshot);
            }
        )) {
            $this->cleanupFailedSnapshot($snapshotPath);
            $this->displaySummary();

            return self::FAILURE;
        }

        $this->displaySummary();
        $this->newLine();
        $this->displaySnapshotInfo($snapshotPath, $checksum);

        return self::SUCCESS;
    }

    /**
     * Clean up a failed snapshot by removing the SQL file if it exists.
     */
    private function cleanupFailedSnapshot(string $snapshotPath): void
    {
        if (File::exists($snapshotPath)) {
            File::delete($snapshotPath);
        }
    }
}
