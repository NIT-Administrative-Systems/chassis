<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Concerns;

use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

/**
 * Shared helpers for recording changes and incrementing counters
 * on the MigrationContext from within a migration step.
 */
trait TracksChanges
{
    /**
     * Record a detailed change entry (file, line, description) in the context's change log.
     */
    protected function recordChange(MigrationContext $context, string $file, int $line, string $description): void
    {
        $context->changeLog[] = [$file, $line, $description];
    }

    /**
     * Increment a named counter on the context.
     *
     * @param  'namespacesRewritten'|'filesDeleted'|'filesScaffolded'  $counter
     */
    protected function incrementCounter(MigrationContext $context, string $counter, int $amount = 1): void
    {
        $context->{$counter} += $amount;
    }
}
