<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate;

use Illuminate\Console\Command;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\CleanPhpunitExclusionsStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\DeleteExtractedFilesStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\RemoveDatetimeDirectiveStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\RewriteMiddlewareRoutesStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\RewriteNamespacesStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\ScaffoldSubclassesStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\UpgradeConfigValidatorsStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps\UpgradeRebuildDatabaseCommandStep;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Migrates a consuming app from inline starter classes to the chassis package.
 *
 * Uses nikic/php-parser for AST-level transformations:
 * 1. Rewrites use statements and class references to new namespaces
 * 2. Transforms DateTimeFormatter::datetime() calls (User model → timezone string)
 * 3. Renames StarterValidator attribute to ValidatesConfig
 * 4. Deletes source/test files now provided by the package
 */
class MigrateToChassisCommand extends Command
{
    protected $signature = 'chassis:migrate
                            {--dry-run : Show what would be changed without making changes}
                            {--skip-tests : Skip deleting test files}
                            {--skip-source : Skip deleting source files}';

    protected $description = 'Migrate from inline starter classes to the chassis package';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $context = new MigrationContext($isDryRun, $this);

        $this->newLine();
        info($isDryRun ? 'Chassis Migration (dry run)' : 'Chassis Migration');

        foreach ($this->steps() as $step) {
            $step->run($context);
        }

        $this->newLine();
        $this->displayReport($context);

        if ($isDryRun && ($context->namespacesRewritten > 0 || $context->filesDeleted > 0 || $context->filesScaffolded > 0)) {
            $this->newLine();
            note('Run without --dry-run to apply these changes.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<MigrationStep>
     */
    private function steps(): array
    {
        return [
            new RewriteNamespacesStep(),
            new DeleteExtractedFilesStep(
                skipSource: (bool) $this->option('skip-source'),
                skipTests: (bool) $this->option('skip-tests'),
            ),
            new ScaffoldSubclassesStep(),
            new RewriteMiddlewareRoutesStep(),
            new RemoveDatetimeDirectiveStep(),
            new UpgradeConfigValidatorsStep(),
            new UpgradeRebuildDatabaseCommandStep(),
            new CleanPhpunitExclusionsStep(),
        ];
    }

    private function displayReport(MigrationContext $context): void
    {
        $this->line('  <fg=gray>─────────────────────────────────────────────────</>');
        $this->newLine();

        // Show detailed change log with file paths and line numbers
        if ($context->changeLog !== []) {
            $this->components->info('Changes:');
            foreach ($context->changeLog as [$file, $line, $description]) {
                $this->line("  <fg=gray>{$file}:{$line}</> {$description}");
            }
            $this->newLine();
        }

        if ($context->conflicts !== []) {
            warning('Conflicts detected — these files were modified locally:');
            $this->newLine();
            foreach ($context->conflicts as $path) {
                $this->line("  <fg=yellow>⚠</> {$path}");
            }
            $this->newLine();
            $this->line('  <fg=gray>Review these files manually to merge your changes.</>');
            $this->newLine();
        }

        $rows = [
            ['Namespace references rewritten', (string) $context->namespacesRewritten],
            ['Files deleted', (string) $context->filesDeleted],
            ['Files scaffolded', (string) $context->filesScaffolded],
        ];

        if ($context->conflicts !== []) {
            $rows[] = ['Conflicts (review manually)', (string) count($context->conflicts)];
        }

        table(['Action', 'Count'], $rows);

        if (! $context->isDryRun && $context->filesDeleted > 0) {
            $this->newLine();
            note('Run `composer dump-autoload` to update the class map.');
        }
    }
}
