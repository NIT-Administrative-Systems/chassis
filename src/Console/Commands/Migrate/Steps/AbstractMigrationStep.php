<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\TracksChanges;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

abstract class AbstractMigrationStep implements MigrationStep
{
    use TracksChanges;

    /**
     * Render the standard heading used before a step begins its work.
     */
    protected function writeStepHeading(MigrationContext $context, string $message): void
    {
        $context->command->newLine();
        $context->command->getOutput()->writeln("<info>{$message}</info>");
    }

    protected function success(MigrationContext $context, string $message): void
    {
        $context->command->line("  <fg=green>✓</> {$message}");
    }

    protected function skip(MigrationContext $context, string $message): void
    {
        $context->command->line("  <fg=yellow>⊘</> {$message}");
    }

    protected function note(MigrationContext $context, string $message): void
    {
        $context->command->line("  <fg=gray>{$message}</>");
    }

    /**
     * Convert an absolute project path into a path relative to `base_path()`.
     */
    protected function toRelativePath(string $absolutePath): string
    {
        return str_replace(base_path() . '/', '', $absolutePath);
    }

    protected function markFileCreated(MigrationContext $context, int $amount = 1): void
    {
        $this->incrementCounter($context, 'filesCreated', $amount);
    }

    protected function markFileModified(MigrationContext $context, int $amount = 1): void
    {
        $this->incrementCounter($context, 'filesModified', $amount);
    }

    /**
     * Record a file that needs manual review because the step could not
     * safely apply a mechanical rewrite.
     */
    protected function recordConflict(MigrationContext $context, string $message): void
    {
        $context->conflicts[] = $message;
    }
}
