<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Attributes;

use Attribute;
use Northwestern\SysDev\Chassis\Contracts\IdempotentSeederInterface;

/**
 * Marks a seeder for automatic discovery and dependency-aware execution.
 *
 * Seeders decorated with this attribute are automatically discovered and executed
 * in dependency order during deployment across all environments.
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class AutoSeed
{
    /**
     * @param  list<class-string<IdempotentSeederInterface>>  $dependsOn  Array of seeder classes that must run first
     */
    public function __construct(
        public array $dependsOn = [],
    ) {
        //
    }
}
