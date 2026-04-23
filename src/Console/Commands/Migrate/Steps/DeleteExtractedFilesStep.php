<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationManifest;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * Phase 3: Delete source and test files now provided by the package,
 * then clean up any directories left empty.
 */
class DeleteExtractedFilesStep extends AbstractMigrationStep
{
    public function __construct(
        private readonly bool $skipSource,
        private readonly bool $skipTests,
    ) {
    }

    public function label(): string
    {
        return 'Checking files for deletion...';
    }

    public function run(MigrationContext $context): void
    {
        if (! $this->skipSource) {
            $this->deleteConfiguredPaths(MigrationManifest::SOURCE_FILES, 'source', $context);
        }

        if (! $this->skipTests) {
            $this->deleteConfiguredPaths(MigrationManifest::TEST_FILES, 'test', $context);
        }
    }

    /**
     * Present the deletion plan for one manifest group and optionally remove it.
     *
     * @param  list<string>  $relativePaths
     */
    private function deleteConfiguredPaths(array $relativePaths, string $type, MigrationContext $context): void
    {
        $this->writeStepHeading($context, "Checking {$type} files for deletion...");

        $pathsMarkedForDeletion = [];
        $missing = [];

        foreach ($relativePaths as $relativePath) {
            $absolutePath = base_path($relativePath);

            if (! File::exists($absolutePath)) {
                $missing[] = $relativePath;

                continue;
            }

            $pathsMarkedForDeletion[] = $relativePath;
        }

        if ($pathsMarkedForDeletion === []) {
            $this->note($context, 'No files to delete');

            return;
        }

        $context->command->newLine();
        foreach ($pathsMarkedForDeletion as $path) {
            $context->command->line('  <fg=red>✗</> ' . $path);
        }

        if ($missing !== []) {
            $context->command->newLine();
            $this->note($context, 'Already removed: ' . count($missing) . ' files');
        }

        $context->command->newLine();
        $this->note($context, count($pathsMarkedForDeletion) . " {$type} files " . ($context->isDryRun ? 'would be' : 'to be') . ' deleted');

        if (! $context->isDryRun) {
            if (! confirm('Delete ' . count($pathsMarkedForDeletion) . " {$type} files?", default: true)) {
                warning("Skipped {$type} file deletion.");

                return;
            }

            foreach ($pathsMarkedForDeletion as $path) {
                $absolutePath = base_path($path);
                if (is_dir($absolutePath)) {
                    File::deleteDirectory($absolutePath);
                } else {
                    File::delete($absolutePath);
                }
                $this->incrementCounter($context, 'filesDeleted');
            }

            // Clean up empty directories
            $this->pruneEmptyParentDirectories($pathsMarkedForDeletion);
        } else {
            $this->incrementCounter($context, 'filesDeleted', count($pathsMarkedForDeletion));
        }
    }

    /**
     * Remove directories that became empty after file deletion.
     *
     * @param  list<string>  $deletedFiles
     */
    private function pruneEmptyParentDirectories(array $deletedFiles): void
    {
        $directories = array_unique(array_map(
            fn (string $path): string => dirname(base_path($path)),
            $deletedFiles
        ));

        // Sort deepest first so we clean up from leaves to root
        usort($directories, fn (string $a, string $b): int => substr_count($b, DIRECTORY_SEPARATOR) - substr_count($a, DIRECTORY_SEPARATOR));

        foreach ($directories as $dir) {
            while (is_dir($dir) && $this->directoryContainsOnlyDotEntries($dir) && $dir !== base_path()) {
                File::deleteDirectory($dir);
                $dir = dirname($dir);
            }
        }
    }

    /**
     * Use a lightweight directory scan instead of Symfony Finder because this
     * runs on every deleted path and only needs to detect `.` and `..`.
     */
    private function directoryContainsOnlyDotEntries(string $path): bool
    {
        $items = scandir($path);

        return $items !== false && count($items) <= 2; // . and ..
    }
}
