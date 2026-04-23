<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\TracksChanges;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationManifest;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * Phase 3: Delete source and test files now provided by the package,
 * then clean up any directories left empty.
 */
class DeleteExtractedFilesStep implements MigrationStep
{
    use TracksChanges;

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
            $this->deleteFiles(MigrationManifest::SOURCE_FILES, 'source', $context);
        }

        if (! $this->skipTests) {
            $this->deleteFiles(MigrationManifest::TEST_FILES, 'test', $context);
        }
    }

    /**
     * @param  list<string>  $files
     */
    private function deleteFiles(array $files, string $type, MigrationContext $context): void
    {
        $context->command->newLine();
        $context->command->getOutput()->writeln("<info>Checking {$type} files for deletion...</info>");

        $toDelete = [];
        $missing = [];

        foreach ($files as $relativePath) {
            $fullPath = base_path($relativePath);

            if (! File::exists($fullPath)) {
                $missing[] = $relativePath;

                continue;
            }

            $toDelete[] = $relativePath;
        }

        if ($toDelete === []) {
            $context->command->line('  <fg=gray>No files to delete</>');

            return;
        }

        $context->command->newLine();
        foreach ($toDelete as $path) {
            $context->command->line('  <fg=red>✗</> ' . $path);
        }

        if ($missing !== []) {
            $context->command->newLine();
            $context->command->line('  <fg=gray>Already removed: ' . count($missing) . ' files</>');
        }

        $context->command->newLine();
        $context->command->line('  <fg=gray>' . count($toDelete) . " {$type} files " . ($context->isDryRun ? 'would be' : 'to be') . ' deleted</>');

        if (! $context->isDryRun) {
            if (! confirm('Delete ' . count($toDelete) . " {$type} files?", default: true)) {
                warning("Skipped {$type} file deletion.");

                return;
            }

            foreach ($toDelete as $path) {
                $fullPath = base_path($path);
                if (is_dir($fullPath)) {
                    File::deleteDirectory($fullPath);
                } else {
                    File::delete($fullPath);
                }
                $this->incrementCounter($context, 'filesDeleted');
            }

            // Clean up empty directories
            $this->cleanEmptyDirectories($toDelete);
        } else {
            $this->incrementCounter($context, 'filesDeleted', count($toDelete));
        }
    }

    /**
     * Remove directories that became empty after file deletion.
     *
     * @param  list<string>  $deletedFiles
     */
    private function cleanEmptyDirectories(array $deletedFiles): void
    {
        $directories = array_unique(array_map(
            fn (string $path): string => dirname(base_path($path)),
            $deletedFiles
        ));

        // Sort deepest first so we clean up from leaves to root
        usort($directories, fn (string $a, string $b): int => substr_count($b, DIRECTORY_SEPARATOR) - substr_count($a, DIRECTORY_SEPARATOR));

        foreach ($directories as $dir) {
            while (is_dir($dir) && $this->isEmptyDirectory($dir) && $dir !== base_path()) {
                File::deleteDirectory($dir);
                $dir = dirname($dir);
            }
        }
    }

    private function isEmptyDirectory(string $path): bool
    {
        $items = scandir($path);

        return $items !== false && count($items) <= 2; // . and ..
    }
}
