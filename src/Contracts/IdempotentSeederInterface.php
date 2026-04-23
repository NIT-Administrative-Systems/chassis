<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Contracts;

/**
 * Marks a seeder as idempotent and allows auto-discovery.
 *
 * In most cases, you should just extend IdempotentSeeder, but in cases where
 * you need to do something more complex than a lookup by exactly one unique
 * column, you might opt to implement this interface and do your own logic.
 */
interface IdempotentSeederInterface
{
    public function run(): void;
}
