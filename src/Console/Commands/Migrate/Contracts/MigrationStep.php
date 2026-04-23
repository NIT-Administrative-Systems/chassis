<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Contracts;

use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

interface MigrationStep
{
    public function label(): string;

    public function run(MigrationContext $context): void;
}
