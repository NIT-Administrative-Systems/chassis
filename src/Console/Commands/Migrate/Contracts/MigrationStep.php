<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts;

use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

interface MigrationStep
{
    /**
     * Human-readable label shown before the step emits its detailed output.
     */
    public function label(): string;

    /**
     * Execute one migration step against the shared mutable context.
     */
    public function run(MigrationContext $context): void;
}
