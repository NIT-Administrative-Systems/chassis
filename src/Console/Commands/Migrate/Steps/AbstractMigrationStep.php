<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns\TracksChanges;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts\MigrationStep;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

abstract class AbstractMigrationStep implements MigrationStep
{
    use TracksChanges;

    protected function writeHeading(MigrationContext $context, string $message): void
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

    protected function relativePath(string $fullPath): string
    {
        return str_replace(base_path() . '/', '', $fullPath);
    }

    protected function markFileCreated(MigrationContext $context, int $amount = 1): void
    {
        $this->incrementCounter($context, 'filesCreated', $amount);
    }

    protected function markFileModified(MigrationContext $context, int $amount = 1): void
    {
        $this->incrementCounter($context, 'filesModified', $amount);
    }

    protected function addConflict(MigrationContext $context, string $message): void
    {
        $context->conflicts[] = $message;
    }
}
