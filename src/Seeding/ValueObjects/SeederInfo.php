<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Seeding\ValueObjects;

use Northwestern\SysDev\Chassis\Contracts\IdempotentSeederInterface;

/**
 * Value object containing metadata for a discovered seeder.
 *
 * Holds the fully qualified class name and dependency information extracted
 * from the #[AutoSeed] attribute during seeder discovery.
 */
readonly class SeederInfo
{
    /**
     * @param  class-string<IdempotentSeederInterface>  $className  Fully qualified class name
     * @param  list<class-string<IdempotentSeederInterface>>  $dependsOn  Array of seeder classes that must run first
     */
    public function __construct(
        public string $className,
        public array $dependsOn = [],
    ) {
        //
    }

    /**
     * Get short class name without namespace
     */
    public function getShortName(): string
    {
        return class_basename($this->className);
    }

    /**
     * Check if this seeder has any dependencies
     */
    public function hasDependencies(): bool
    {
        return filled($this->dependsOn);
    }

    /**
     * Get short names of dependencies
     *
     * @return list<non-empty-string>
     */
    public function getDependencyShortNames(): array
    {
        return array_values(array_filter(
            array_map(class_basename(...), $this->dependsOn),
            fn (string $name): bool => $name !== '',
        ));
    }
}
